package integration

import (
	"context"
	"fmt"
	"log"
	"testing"

	"github.com/joho/godotenv"
	"github.com/yhonda-ohishi/dtako_events/internal/config"
	"github.com/yhonda-ohishi/dtako_events/internal/repository"
	"github.com/yhonda-ohishi/dtako_events/internal/service"
	pb "github.com/yhonda-ohishi/dtako_events/proto"
)

// GetRowDetail機能のテスト
func TestGetRowDetailFunction(t *testing.T) {
	if testing.Short() {
		t.Skip("Skipping view function test in short mode")
	}

	// .envファイルの読み込み
	if err := godotenv.Load("../../.env"); err != nil {
		log.Printf("Warning: .env file not found: %v", err)
	}

	// データベース接続
	dbConfig := config.LoadDatabaseConfig()
	db, err := config.ConnectDatabase(dbConfig)
	if err != nil {
		t.Fatalf("Failed to connect to database: %v", err)
	}

	sqlDB, err := db.DB()
	if err != nil {
		t.Fatalf("Failed to get database instance: %v", err)
	}
	defer sqlDB.Close()

	t.Log("Successfully connected to database")

	// リポジトリ初期化
	rowRepo := repository.NewDtakoRowRepository(db)
	eventRepo := repository.NewDtakoEventRepository(db)
	fuelTankaRepo := repository.NewFuelTankaRepository(db)

	// 一番星DBは省略（テスト用）
	var ichibanRepo repository.IchibanRepository

	// サービス初期化（desktop-serverなし）
	dtakoRowService := service.NewDtakoRowService(rowRepo, eventRepo, ichibanRepo, fuelTankaRepo, nil)

	// テスト用の運行IDを取得（最新のdtako_row）
	type RowID struct {
		ID string `gorm:"column:id"`
	}
	var row RowID
	result := db.Table("dtako_rows").
		Order("出庫日時 DESC").
		First(&row)

	if result.Error != nil {
		t.Fatalf("Failed to get test data: %v", result.Error)
	}

	t.Logf("Testing GetRowDetail with ID: %s", row.ID)

	// GetRowDetail実行
	ctx := context.Background()
	resp, err := dtakoRowService.GetRowDetail(ctx, &pb.GetRowDetailRequest{
		Id: row.ID,
	})

	if err != nil {
		t.Fatalf("GetRowDetail failed: %v", err)
	}

	// レスポンスの検証
	t.Log("\n=== GetRowDetail Response ===")

	// 運行データ本体
	if resp.DtakoRow != nil {
		t.Logf("DtakoRow:")
		t.Logf("  ID: %s", resp.DtakoRow.Id)
		t.Logf("  運行NO: %s", resp.DtakoRow.UnkoNo)
		t.Logf("  車番: %s", resp.DtakoRow.Shaban)
		t.Logf("  運転手ID: %s", resp.DtakoRow.DriverId)
		t.Logf("  出庫日時: %v", resp.DtakoRow.StartDatetime.AsTime())
		if resp.DtakoRow.EndDatetime != nil {
			t.Logf("  帰庫日時: %v", resp.DtakoRow.EndDatetime.AsTime())
		}
		if resp.DtakoRow.Driver != nil {
			t.Logf("  運転手: %s (%s)", resp.DtakoRow.Driver.Name, resp.DtakoRow.Driver.Code)
		}
	} else {
		t.Error("DtakoRow is nil")
	}

	// イベントデータ
	t.Logf("\nEvents: %d件", len(resp.Events))
	for i, event := range resp.Events {
		if i < 5 { // 最初の5件だけ表示
			t.Logf("  [%d] %s - %s (開始: %v)",
				i+1,
				event.SrchId,
				event.EventType,
				event.StartDatetime.AsTime())
		}
	}

	// 積み・降しペア
	t.Logf("\nTsumiOroshiPairs: %d組", len(resp.TsumiOroshiPairs))
	for i, pair := range resp.TsumiOroshiPairs {
		if i < 3 { // 最初の3組だけ表示
			tsumiInfo := "なし"
			if pair.Tsumi != nil {
				tsumiInfo = fmt.Sprintf("%s (%v)", pair.Tsumi.EventType, pair.Tsumi.StartDatetime.AsTime())
			}
			oroshiInfo := "なし"
			if pair.Oroshi != nil {
				oroshiInfo = fmt.Sprintf("%s (%v)", pair.Oroshi.EventType, pair.Oroshi.StartDatetime.AsTime())
			}
			t.Logf("  [%d] 積み: %s → 降し: %s", i+1, tsumiInfo, oroshiInfo)
		}
	}

	// 前後の運行データ
	if resp.DtakoLast != nil {
		t.Logf("\nDtakoLast: %s (出庫: %v)",
			resp.DtakoLast.Id,
			resp.DtakoLast.StartDatetime.AsTime())
	} else {
		t.Log("\nDtakoLast: なし")
	}

	if resp.DtakoNext != nil {
		t.Logf("DtakoNext: %s (出庫: %v)",
			resp.DtakoNext.Id,
			resp.DtakoNext.StartDatetime.AsTime())
	} else {
		t.Log("DtakoNext: なし")
	}

	// 次が降し最初
	if resp.IsNextOroshiFst != nil {
		t.Logf("IsNextOroshiFst: %s", resp.IsNextOroshiFst.EventType)
	}

	// 前が積み最後
	if resp.IsLastOroshiLast != nil {
		t.Logf("IsLastOroshiLast: %s", resp.IsLastOroshiLast.EventType)
	}

	// フェリーデータ
	t.Logf("\nDferry: %d件", len(resp.Dferry))
	for i, ferry := range resp.Dferry {
		if i < 3 {
			t.Logf("  [%d] %s - 金額: %d円", i+1, ferry.FerryName, ferry.Kingaku)
		}
	}

	// ETCデータ
	t.Logf("\nDdetc (EtcMeisai): %d件", len(resp.Ddetc))
	t.Logf("DdetcSrchCount (未設定): %d件", resp.DdetcSrchCount)
	for i, etc := range resp.Ddetc {
		if i < 3 {
			t.Logf("  [%d] ID=%s, 金額=%d円, 自社=%d",
				i+1, etc.Id, etc.Kingaku, etc.Jisha)
		}
	}

	// 売上経費データ
	t.Logf("\nDUriage: %d件", len(resp.DUriage))
	for i, uriage := range resp.DUriage {
		if i < 3 {
			t.Logf("  [%d] SrchID=%s, 経費C=%d, 金額=%d円",
				i+1, uriage.SrchId, uriage.KeihiC, uriage.Kingaku)
		}
	}

	// 料費データ
	t.Logf("\nRyohiRows: %d件", len(resp.RyohiRows))
	for i, ryohi := range resp.RyohiRows {
		if i < 3 {
			t.Logf("  [%d] ID=%d, 得意先=%s, 積日=%s, 卸日=%s",
				i+1, ryohi.Id, ryohi.Tokuisaki, ryohi.TsumiDate, ryohi.OroshiDate)
		}
	}

	// 一番星データ（省略されている場合）
	t.Logf("\nIchiR (一番星運転日報): %d件", len(resp.IchiR))
	t.Logf("Keihi (経費明細): %d件", len(resp.Keihi))

	// 燃料単価
	if resp.FuelTanka != nil {
		t.Logf("\nFuelTanka: 月=%d, 単価=%.2f円/L",
			resp.FuelTanka.MonthInt, resp.FuelTanka.Tanka)
	} else {
		t.Log("\nFuelTanka: なし")
	}

	// 積み降しイベント一覧
	t.Logf("\nTsumiOroshi (積み降しイベント): %d件", len(resp.TsumiOroshi))

	t.Log("\n=== Test Completed Successfully ===")
}

// 複数のIDでview機能をテスト
func TestGetRowDetailMultiple(t *testing.T) {
	if testing.Short() {
		t.Skip("Skipping multiple view test in short mode")
	}

	// .envファイルの読み込み
	if err := godotenv.Load("../../.env"); err != nil {
		log.Printf("Warning: .env file not found: %v", err)
	}

	// データベース接続
	dbConfig := config.LoadDatabaseConfig()
	db, err := config.ConnectDatabase(dbConfig)
	if err != nil {
		t.Fatalf("Failed to connect to database: %v", err)
	}

	sqlDB, err := db.DB()
	if err != nil {
		t.Fatalf("Failed to get database instance: %v", err)
	}
	defer sqlDB.Close()

	// リポジトリ初期化
	rowRepo := repository.NewDtakoRowRepository(db)
	eventRepo := repository.NewDtakoEventRepository(db)
	fuelTankaRepo := repository.NewFuelTankaRepository(db)

	// サービス初期化（desktop-serverなし）
	dtakoRowService := service.NewDtakoRowService(rowRepo, eventRepo, nil, fuelTankaRepo, nil)

	// 最新5件の運行IDを取得
	type RowID struct {
		ID string `gorm:"column:id"`
	}
	var rows []RowID
	result := db.Table("dtako_rows").
		Order("出庫日時 DESC").
		Limit(5).
		Find(&rows)

	if result.Error != nil {
		t.Fatalf("Failed to get test data: %v", result.Error)
	}

	t.Logf("Testing GetRowDetail with %d IDs", len(rows))

	ctx := context.Background()
	successCount := 0
	errorCount := 0

	for i, row := range rows {
		t.Logf("\n[%d/%d] Testing ID: %s", i+1, len(rows), row.ID)

		resp, err := dtakoRowService.GetRowDetail(ctx, &pb.GetRowDetailRequest{
			Id: row.ID,
		})

		if err != nil {
			t.Logf("  ❌ Error: %v", err)
			errorCount++
			continue
		}

		successCount++
		t.Logf("  ✅ Success:")
		t.Logf("     運行NO: %s", resp.DtakoRow.UnkoNo)
		t.Logf("     イベント数: %d", len(resp.Events))
		t.Logf("     積み降しペア: %d組", len(resp.TsumiOroshiPairs))
		t.Logf("     フェリー: %d件", len(resp.Dferry))
		t.Logf("     ETC: %d件", len(resp.Ddetc))
	}

	t.Logf("\n=== Summary ===")
	t.Logf("Total: %d", len(rows))
	t.Logf("Success: %d", successCount)
	t.Logf("Error: %d", errorCount)

	if errorCount > 0 {
		t.Errorf("Some tests failed: %d errors", errorCount)
	}
}
