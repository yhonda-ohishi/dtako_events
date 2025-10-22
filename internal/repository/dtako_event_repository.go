package repository

import (
	"context"
	"time"

	"github.com/yhonda-ohishi/dtako_events/internal/models"
	"gorm.io/gorm"
)

// DtakoEventRepository イベントデータリポジトリ（読み取り専用）
type DtakoEventRepository interface {
	GetByID(ctx context.Context, srchID string) (*models.DtakoEvent, error)
	GetByUnkoNo(ctx context.Context, unkoNo string, eventTypes []string, startTime, endTime *time.Time) ([]*models.DtakoEvent, error)
	AggregateByEventType(ctx context.Context, unkoNo string, startTime, endTime *time.Time) (map[string]*EventTypeStats, error)
}

// EventTypeStats イベント種別ごとの集計結果
type EventTypeStats struct {
	EventType              string
	Count                  int
	TotalDurationMinutes   float64
	AvgDurationMinutes     float64
	TotalSectionDistance   float64  // db_serviceから取得
	AvgSectionDistance     float64  // db_serviceから取得
	TotalMileageDiff       float64  // db_serviceから取得
	AvgMileageDiff         float64  // db_serviceから取得
}

type dtakoEventRepository struct {
	db               *gorm.DB
	dbServiceClient  interface{} // TODO: db_serviceのgRPCクライアント型に変更
}

// NewDtakoEventRepository リポジトリを作成
func NewDtakoEventRepository(db *gorm.DB) DtakoEventRepository {
	return &dtakoEventRepository{
		db:              db,
		dbServiceClient: nil, // TODO: desktop-server経由でdb_serviceクライアントを渡す
	}
}

func (r *dtakoEventRepository) GetByID(ctx context.Context, srchID string) (*models.DtakoEvent, error) {
	var event models.DtakoEvent
	err := r.db.WithContext(ctx).
		Preload("DtakoEventsDetail").
		First(&event, "srch_id = ?", srchID).Error
	if err != nil {
		return nil, err
	}
	return &event, nil
}

func (r *dtakoEventRepository) GetByUnkoNo(ctx context.Context, unkoNo string, eventTypes []string, startTime, endTime *time.Time) ([]*models.DtakoEvent, error) {
	var events []*models.DtakoEvent
	query := r.db.WithContext(ctx).Where("運行NO = ?", unkoNo)

	if len(eventTypes) > 0 {
		query = query.Where("イベント名 IN ?", eventTypes)
	}

	if startTime != nil {
		query = query.Where("開始日時 >= ?", *startTime)
	}

	if endTime != nil {
		query = query.Where("開始日時 <= ?", *endTime)
	}

	err := query.
		Preload("DtakoEventsDetail").
		Order("開始日時 ASC").
		Find(&events).Error

	return events, err
}

func (r *dtakoEventRepository) AggregateByEventType(ctx context.Context, unkoNo string, startTime, endTime *time.Time) (map[string]*EventTypeStats, error) {
	// TODO: 実装方法
	// 1. desktop-serverが提供するdb_service経由でDTakoEventsを取得
	// 2. イベント種別ごとにグループ化して集計
	//
	// 現状はローカルDBのみで集計（距離情報なし）

	var events []*models.DtakoEvent
	query := r.db.WithContext(ctx)

	if unkoNo != "" {
		query = query.Where("運行NO = ?", unkoNo)
	}

	if startTime != nil {
		query = query.Where("開始日時 >= ?", *startTime)
	}

	if endTime != nil {
		query = query.Where("開始日時 <= ?", *endTime)
	}

	if err := query.Find(&events).Error; err != nil {
		return nil, err
	}

	// イベント種別ごとに集計
	stats := make(map[string]*EventTypeStats)

	for _, event := range events {
		if _, exists := stats[event.EventName]; !exists {
			stats[event.EventName] = &EventTypeStats{
				EventType: event.EventName,
			}
		}

		stat := stats[event.EventName]
		stat.Count++

		// 時間計算
		if event.EndDateTime != nil {
			durationMinutes := event.EndDateTime.Sub(event.StartDateTime).Minutes()
			stat.TotalDurationMinutes += durationMinutes
		}
	}

	// 平均を計算
	for _, stat := range stats {
		if stat.Count > 0 {
			stat.AvgDurationMinutes = stat.TotalDurationMinutes / float64(stat.Count)
		}
	}

	// TODO: db_serviceから距離情報を取得して統合
	// stat.TotalSectionDistance, stat.AvgSectionDistance
	// stat.TotalMileageDiff, stat.AvgMileageDiff

	return stats, nil
}
