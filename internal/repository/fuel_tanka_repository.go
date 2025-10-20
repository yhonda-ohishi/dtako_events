package repository

import (
	"context"

	"github.com/yhonda-ohishi/dtako_events/internal/models"
	"gorm.io/gorm"
)

// FuelTankaRepository 燃料単価リポジトリ
type FuelTankaRepository interface {
	// 指定月以前の最新の燃料単価を取得
	GetLatestByMonthInt(ctx context.Context, monthInt int) (*models.DtakoFuelTanka, error)
}

type fuelTankaRepository struct {
	db *gorm.DB
}

// NewFuelTankaRepository リポジトリを作成
func NewFuelTankaRepository(db *gorm.DB) FuelTankaRepository {
	return &fuelTankaRepository{db: db}
}

func (r *fuelTankaRepository) GetLatestByMonthInt(ctx context.Context, monthInt int) (*models.DtakoFuelTanka, error) {
	var tanka models.DtakoFuelTanka
	err := r.db.WithContext(ctx).
		Where("month_int <= ?", monthInt).
		Order("month_int DESC").
		First(&tanka).Error

	if err == gorm.ErrRecordNotFound {
		return nil, nil
	}
	if err != nil {
		return nil, err
	}
	return &tanka, nil
}
