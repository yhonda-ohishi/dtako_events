package repository

import (
	"context"
	"time"

	"github.com/yhonda-ohishi/dtako_events/internal/models"
	"gorm.io/gorm"
)

// IchibanRepository 一番星DBリポジトリ
type IchibanRepository interface {
	// 運転日報明細を取得
	GetUntennippoMeisai(ctx context.Context, unkoYMDFrom, unkoYMDTo time.Time, untenshC, sharyouCC string) ([]*models.IchibanUntennippoMeisai, error)

	// 経費明細を取得
	GetKeihiMeisai(ctx context.Context, unkoYMDFrom, unkoYMDTo time.Time, untenshC, sharyouCC string) ([]*models.IchibanKeihiMeisai, error)
}

type ichibanRepository struct {
	db *gorm.DB // 一番星DB用の接続
}

// NewIchibanRepository リポジトリを作成
func NewIchibanRepository(db *gorm.DB) IchibanRepository {
	return &ichibanRepository{db: db}
}

func (r *ichibanRepository) GetUntennippoMeisai(ctx context.Context, unkoYMDFrom, unkoYMDTo time.Time, untenshC, sharyouCC string) ([]*models.IchibanUntennippoMeisai, error) {
	var meisai []*models.IchibanUntennippoMeisai

	query := r.db.WithContext(ctx).
		Table("運転日報明細").
		Select(`
			車輌C+車輌H as 車輌CC,
			積込年月日,
			運行年月日,
			納入年月日,
			発地N,
			着地N,
			品名N,
			d.得意先N,
			d.得意先C+d.得意先H as 得意先CC,
			備考,
			備考2,
			請求K,
			e.社員N,
			運転手C,
			金額,
			値引,
			割増,
			実費,
			f.社員N as 入力担当N
		`).
		Joins("LEFT JOIN 得意先ﾏｽﾀ d ON d.得意先C+d.得意先H = 運転日報明細.得意先C+運転日報明細.得意先H").
		Joins("LEFT JOIN 社員ﾏｽﾀ e ON e.社員C = 運転日報明細.運転手C").
		Joins("LEFT JOIN 社員ﾏｽﾀ f ON f.社員C = 運転日報明細.入力担当C").
		Where("配車K = ? AND 日報K = ? AND 請求K IN ?", 0, 1, []int{0, 2}).
		Where("運転手C = ? AND 車輌C+車輌H = ?", untenshC, sharyouCC).
		Where("積込年月日 >= ? AND 積込年月日 <= ?", unkoYMDFrom, unkoYMDTo).
		Where("運転日報明細.得意先C+運転日報明細.得意先H <> ?", "000002").
		Order("積込年月日 ASC, 金額 ASC")

	err := query.Find(&meisai).Error
	return meisai, err
}

func (r *ichibanRepository) GetKeihiMeisai(ctx context.Context, unkoYMDFrom, unkoYMDTo time.Time, untenshC, sharyouCC string) ([]*models.IchibanKeihiMeisai, error) {
	var meisai []*models.IchibanKeihiMeisai

	query := r.db.WithContext(ctx).
		Table("経費明細").
		Select(`
			車輌C+車輌H as 車輌CC,
			運行年月日,
			計上年月日,
			数量,
			単価,
			金額,
			備考,
			f.経費N,
			f.経費C,
			d.未払先N
		`).
		Joins("LEFT JOIN 経費ﾏｽﾀ f ON f.経費C = 経費明細.経費C").
		Joins("LEFT JOIN 未払先ﾏｽﾀ d ON d.未払先C = 経費明細.未払先C AND d.未払先H = 経費明細.未払先H").
		Where("運転手C = ? AND 車輌C+車輌H = ?", untenshC, sharyouCC).
		Where("運行年月日 >= ? AND 運行年月日 <= ?", unkoYMDFrom, unkoYMDTo).
		Order("運行年月日 ASC, 計上年月日 ASC")

	err := query.Find(&meisai).Error
	return meisai, err
}
