package repository

import (
	"context"
	"time"

	"github.com/yhonda-ohishi/dtako_events/internal/models"
	"gorm.io/gorm"
)

// DtakoRowRepository 運行データリポジトリ
type DtakoRowRepository interface {
	// 基本CRUD
	Create(ctx context.Context, row *models.DtakoRow) error
	GetByID(ctx context.Context, id string) (*models.DtakoRow, error)
	Update(ctx context.Context, row *models.DtakoRow) error
	Delete(ctx context.Context, id string) error
	List(ctx context.Context, page, pageSize int) ([]*models.DtakoRow, int64, error)

	// View機能用の詳細取得
	GetDetailByID(ctx context.Context, id string) (*models.DtakoRow, error)

	// 前後の運行データ取得
	GetPreviousRow(ctx context.Context, jomuinCD1, sharyouCC string, shukkoDateTime time.Time) (*models.DtakoRow, error)
	GetNextRow(ctx context.Context, jomuinCD1, sharyouCC string, shukkoDateTime time.Time, taishouJomuinKubun int) (*models.DtakoRow, error)

	// 検索
	Search(ctx context.Context, dateFrom, dateTo *time.Time, driverID, shaban string) ([]*models.DtakoRow, error)
}

type dtakoRowRepository struct {
	db *gorm.DB
}

// NewDtakoRowRepository リポジトリを作成
func NewDtakoRowRepository(db *gorm.DB) DtakoRowRepository {
	return &dtakoRowRepository{db: db}
}

func (r *dtakoRowRepository) Create(ctx context.Context, row *models.DtakoRow) error {
	return r.db.WithContext(ctx).Create(row).Error
}

func (r *dtakoRowRepository) GetByID(ctx context.Context, id string) (*models.DtakoRow, error) {
	var row models.DtakoRow
	err := r.db.WithContext(ctx).
		Preload("Driver").
		First(&row, "id = ?", id).Error
	if err != nil {
		return nil, err
	}
	return &row, nil
}

func (r *dtakoRowRepository) GetDetailByID(ctx context.Context, id string) (*models.DtakoRow, error) {
	var row models.DtakoRow
	err := r.db.WithContext(ctx).
		Preload("DtakoEvents", func(db *gorm.DB) *gorm.DB {
			return db.Where("イベント名 IN ?", []string{
				"運行開始", "運転", "休憩", "休息", "積み", "降し", "運行終了",
			}).Order("開始日時 ASC")
		}).
		Preload("DtakoEvents.DtakoEventsDetail").
		Preload("Driver").
		Preload("DtakoFerryRows", func(db *gorm.DB) *gorm.DB {
			return db.Order("開始日時 ASC")
		}).
		Preload("RyohiRows").
		Preload("EtcMeisai", func(db *gorm.DB) *gorm.DB {
			return db.Order("date_to ASC, date_fr ASC")
		}).
		Preload("EtcMeisai.DtakoUriageKeihi").
		Preload("DtakoUriageKeihi", func(db *gorm.DB) *gorm.DB {
			return db.Where("keihi_c = ?", 0).Order("keihi_c ASC, datetime ASC")
		}).
		Preload("DtakoUriageKeihi.DtakoUriageKeihiChild").
		First(&row, "id = ?", id).Error
	if err != nil {
		return nil, err
	}
	return &row, nil
}

func (r *dtakoRowRepository) Update(ctx context.Context, row *models.DtakoRow) error {
	return r.db.WithContext(ctx).Save(row).Error
}

func (r *dtakoRowRepository) Delete(ctx context.Context, id string) error {
	return r.db.WithContext(ctx).Delete(&models.DtakoRow{}, "id = ?", id).Error
}

func (r *dtakoRowRepository) List(ctx context.Context, page, pageSize int) ([]*models.DtakoRow, int64, error) {
	var rows []*models.DtakoRow
	var total int64

	offset := (page - 1) * pageSize

	if err := r.db.WithContext(ctx).Model(&models.DtakoRow{}).Count(&total).Error; err != nil {
		return nil, 0, err
	}

	err := r.db.WithContext(ctx).
		Preload("Driver").
		Offset(offset).
		Limit(pageSize).
		Order("出庫日時 DESC").
		Find(&rows).Error

	return rows, total, err
}

func (r *dtakoRowRepository) GetPreviousRow(ctx context.Context, jomuinCD1, sharyouCC string, shukkoDateTime time.Time) (*models.DtakoRow, error) {
	var row models.DtakoRow
	err := r.db.WithContext(ctx).
		Where("乗務員CD1 = ? AND 車輌CC = ?", jomuinCD1, sharyouCC).
		Where("出庫日時 < ?", shukkoDateTime).
		Preload("DtakoEvents").
		Order("出庫日時 DESC").
		First(&row).Error

	if err == gorm.ErrRecordNotFound {
		return nil, nil
	}
	if err != nil {
		return nil, err
	}
	return &row, nil
}

func (r *dtakoRowRepository) GetNextRow(ctx context.Context, jomuinCD1, sharyouCC string, shukkoDateTime time.Time, taishouJomuinKubun int) (*models.DtakoRow, error) {
	var row models.DtakoRow
	err := r.db.WithContext(ctx).
		Where("乗務員CD1 = ? AND 車輌CC = ? AND 対象乗務員区分 = ?", jomuinCD1, sharyouCC, taishouJomuinKubun).
		Where("出庫日時 > ?", shukkoDateTime).
		Preload("DtakoEvents").
		Order("出庫日時 ASC").
		First(&row).Error

	if err == gorm.ErrRecordNotFound {
		return nil, nil
	}
	if err != nil {
		return nil, err
	}
	return &row, nil
}

func (r *dtakoRowRepository) Search(ctx context.Context, dateFrom, dateTo *time.Time, driverID, shaban string) ([]*models.DtakoRow, error) {
	query := r.db.WithContext(ctx).Preload("Driver")

	if dateFrom != nil {
		query = query.Where("帰庫日時 >= ?", dateFrom)
	}
	if dateTo != nil {
		query = query.Where("帰庫日時 <= ?", dateTo)
	}
	if driverID != "" {
		query = query.Where("乗務員CD1 = ?", driverID)
	}
	if shaban != "" {
		query = query.Where("車輌CC = ?", shaban)
	}

	var rows []*models.DtakoRow
	err := query.Order("出庫日時 DESC").Find(&rows).Error
	return rows, err
}
