package repository

import (
	"context"
	"time"

	"github.com/yhonda-ohishi/dtako_events/internal/models"
	"gorm.io/gorm"
)

// DtakoEventRepository イベントデータリポジトリ
type DtakoEventRepository interface {
	Create(ctx context.Context, event *models.DtakoEvent) error
	GetByID(ctx context.Context, srchID string) (*models.DtakoEvent, error)
	Update(ctx context.Context, event *models.DtakoEvent) error
	Delete(ctx context.Context, srchID string) error
	List(ctx context.Context, page, pageSize int) ([]*models.DtakoEvent, int64, error)

	// 運行NO指定でイベント一覧取得
	GetByUnkoNo(ctx context.Context, unkoNo string, eventTypes []string) ([]*models.DtakoEvent, error)

	// 積み・降しイベント取得
	GetTsumiOroshiByUnkoNo(ctx context.Context, unkoNo string) ([]*models.DtakoEvent, error)

	// 特定の日時より前のイベント取得
	GetEventsBeforeDateTime(ctx context.Context, unkoNo string, beforeDateTime time.Time, eventTypes []string) ([]*models.DtakoEvent, error)
}

type dtakoEventRepository struct {
	db *gorm.DB
}

// NewDtakoEventRepository リポジトリを作成
func NewDtakoEventRepository(db *gorm.DB) DtakoEventRepository {
	return &dtakoEventRepository{db: db}
}

func (r *dtakoEventRepository) Create(ctx context.Context, event *models.DtakoEvent) error {
	return r.db.WithContext(ctx).Create(event).Error
}

func (r *dtakoEventRepository) GetByID(ctx context.Context, srchID string) (*models.DtakoEvent, error) {
	var event models.DtakoEvent
	err := r.db.WithContext(ctx).
		Preload("DtakoEventsDetail").
		Preload("Driver").
		First(&event, "srch_id = ?", srchID).Error
	if err != nil {
		return nil, err
	}
	return &event, nil
}

func (r *dtakoEventRepository) Update(ctx context.Context, event *models.DtakoEvent) error {
	return r.db.WithContext(ctx).Save(event).Error
}

func (r *dtakoEventRepository) Delete(ctx context.Context, srchID string) error {
	return r.db.WithContext(ctx).Delete(&models.DtakoEvent{}, "srch_id = ?", srchID).Error
}

func (r *dtakoEventRepository) List(ctx context.Context, page, pageSize int) ([]*models.DtakoEvent, int64, error) {
	var events []*models.DtakoEvent
	var total int64

	offset := (page - 1) * pageSize

	if err := r.db.WithContext(ctx).Model(&models.DtakoEvent{}).Count(&total).Error; err != nil {
		return nil, 0, err
	}

	err := r.db.WithContext(ctx).
		Preload("Driver").
		Offset(offset).
		Limit(pageSize).
		Order("開始日時 DESC").
		Find(&events).Error

	return events, total, err
}

func (r *dtakoEventRepository) GetByUnkoNo(ctx context.Context, unkoNo string, eventTypes []string) ([]*models.DtakoEvent, error) {
	var events []*models.DtakoEvent
	query := r.db.WithContext(ctx).Where("運行NO = ?", unkoNo)

	if len(eventTypes) > 0 {
		query = query.Where("イベント名 IN ?", eventTypes)
	}

	err := query.
		Preload("DtakoEventsDetail").
		Order("開始日時 ASC").
		Find(&events).Error

	return events, err
}

func (r *dtakoEventRepository) GetTsumiOroshiByUnkoNo(ctx context.Context, unkoNo string) ([]*models.DtakoEvent, error) {
	return r.GetByUnkoNo(ctx, unkoNo, []string{"積み", "降し"})
}

func (r *dtakoEventRepository) GetEventsBeforeDateTime(ctx context.Context, unkoNo string, beforeDateTime time.Time, eventTypes []string) ([]*models.DtakoEvent, error) {
	var events []*models.DtakoEvent
	query := r.db.WithContext(ctx).
		Where("運行NO = ?", unkoNo).
		Where("開始日時 < ?", beforeDateTime)

	if len(eventTypes) > 0 {
		query = query.Where("イベント名 IN ?", eventTypes)
	}

	err := query.
		Order("開始日時 DESC").
		Find(&events).Error

	return events, err
}
