package repository

import (
	"context"
	"fmt"
	"strconv"
	"time"

	"github.com/yhonda-ohishi/dtako_events/internal/client"
	"github.com/yhonda-ohishi/dtako_events/internal/models"
)

// DesktopServerRepository desktop-server経由でデータを取得するリポジトリ
type DesktopServerRepository struct {
	client *client.DesktopServerClient
}

// NewDesktopServerRepository リポジトリを作成
func NewDesktopServerRepository(client *client.DesktopServerClient) *DesktopServerRepository {
	return &DesktopServerRepository{
		client: client,
	}
}

// GetETCMeisaiByRowID 運行IDからETC明細を取得
func (r *DesktopServerRepository) GetETCMeisaiByRowID(ctx context.Context, rowID string) ([]*models.EtcMeisai, error) {
	sql := `SELECT * FROM etc_meisai WHERE dtako_row_id = ? ORDER BY date_to ASC, date_fr ASC`

	resp, err := r.client.QueryDatabase(ctx, sql, rowID)
	if err != nil {
		return nil, fmt.Errorf("failed to query etc_meisai: %w", err)
	}

	result := make([]*models.EtcMeisai, 0, len(resp.Rows))
	for _, row := range resp.Rows {
		etcMeisai := &models.EtcMeisai{
			ID:         parseUint(row.Columns["id"]),
			DtakoRowID: row.Columns["dtako_row_id"],
			DateFr:     parseDateTime(row.Columns["date_fr"]),
			DateTo:     parseDateTime(row.Columns["date_to"]),
			Kingaku:    parseInt(row.Columns["kingaku"]),
			CreatedAt:  parseDateTime(row.Columns["created"]),
			UpdatedAt:  parseDateTime(row.Columns["modified"]),
		}
		result = append(result, etcMeisai)
	}

	return result, nil
}

// GetUriageKeihiByRowID 運行IDから売上経費を取得
func (r *DesktopServerRepository) GetUriageKeihiByRowID(ctx context.Context, rowID string) ([]*models.DtakoUriageKeihi, error) {
	sql := `SELECT * FROM dtako_uriage_keihi WHERE dtako_row_id = ? AND keihi_c = 0 ORDER BY keihi_c ASC, datetime ASC`

	resp, err := r.client.QueryDatabase(ctx, sql, rowID)
	if err != nil {
		return nil, fmt.Errorf("failed to query dtako_uriage_keihi: %w", err)
	}

	result := make([]*models.DtakoUriageKeihi, 0, len(resp.Rows))
	for _, row := range resp.Rows {
		uriage := &models.DtakoUriageKeihi{
			SrchID:     row.Columns["srch_id"],
			DtakoRowID: row.Columns["dtako_row_id"],
			KeihiC:     parseInt(row.Columns["keihi_c"]),
			Datetime:   parseDateTime(row.Columns["datetime"]),
			Kingaku:    parseInt(row.Columns["kingaku"]),
			CreatedAt:  parseDateTime(row.Columns["created"]),
			UpdatedAt:  parseDateTime(row.Columns["modified"]),
		}
		result = append(result, uriage)
	}

	return result, nil
}

// GetFerryRowsByRowID 運行IDからフェリーデータを取得
func (r *DesktopServerRepository) GetFerryRowsByRowID(ctx context.Context, rowID string) ([]*models.DtakoFerryRow, error) {
	sql := `SELECT * FROM dtako_ferry_rows WHERE dtako_row_id = ? ORDER BY 乗船時刻 ASC`

	resp, err := r.client.QueryDatabase(ctx, sql, rowID)
	if err != nil {
		return nil, fmt.Errorf("failed to query dtako_ferry_rows: %w", err)
	}

	result := make([]*models.DtakoFerryRow, 0, len(resp.Rows))
	for _, row := range resp.Rows {
		endDateTime := parseDateTime(row.Columns["終了日時"])
		ferry := &models.DtakoFerryRow{
			ID:            parseUint(row.Columns["id"]),
			UnkoNo:        row.Columns["運行NO"],
			StartDateTime: parseDateTime(row.Columns["開始日時"]),
			EndDateTime:   &endDateTime,
			FerryName:     row.Columns["フェリー名"],
			Kingaku:       parseInt(row.Columns["金額"]),
			CreatedAt:     parseDateTime(row.Columns["created"]),
			UpdatedAt:     parseDateTime(row.Columns["modified"]),
		}
		result = append(result, ferry)
	}

	return result, nil
}

// GetRyohiRowsByRowID 運行IDから料費データを取得
func (r *DesktopServerRepository) GetRyohiRowsByRowID(ctx context.Context, rowID string) ([]*models.RyohiRow, error) {
	sql := `SELECT * FROM ryohi_rows WHERE dtako_row_id = ?`

	resp, err := r.client.QueryDatabase(ctx, sql, rowID)
	if err != nil {
		return nil, fmt.Errorf("failed to query ryohi_rows: %w", err)
	}

	result := make([]*models.RyohiRow, 0, len(resp.Rows))
	for _, row := range resp.Rows {
		ryohi := &models.RyohiRow{
			ID:        parseUint(row.Columns["id"]),
			UnkoNo:    row.Columns["unko_no"],
			TsumiDate: row.Columns["tsumi_date"],
			OroshiDate: row.Columns["oroshi_date"],
			Tokuisaki: row.Columns["tokuisaki"],
			Status:    row.Columns["status"],
			CreatedAt: parseDateTime(row.Columns["created"]),
			UpdatedAt: parseDateTime(row.Columns["modified"]),
		}
		result = append(result, ryohi)
	}

	return result, nil
}

// Helper functions

func parseUint(s string) uint {
	if s == "" {
		return 0
	}
	val, _ := strconv.ParseUint(s, 10, 32)
	return uint(val)
}

func parseInt(s string) int {
	if s == "" {
		return 0
	}
	val, _ := strconv.Atoi(s)
	return val
}

func parseFloat(s string) float64 {
	if s == "" {
		return 0.0
	}
	val, _ := strconv.ParseFloat(s, 64)
	return val
}

func parseDateTime(s string) time.Time {
	if s == "" {
		return time.Time{}
	}

	// 複数のフォーマットを試行
	formats := []string{
		"2006-01-02 15:04:05",
		"2006-01-02T15:04:05Z",
		"2006-01-02T15:04:05",
		time.RFC3339,
	}

	for _, format := range formats {
		if t, err := time.Parse(format, s); err == nil {
			return t
		}
	}

	return time.Time{}
}
