package integration

import (
	"context"
	"fmt"
	"log"
	"os"
	"testing"

	"github.com/joho/godotenv"
	"github.com/yhonda-ohishi/dtako_events/internal/client"
)

// Desktop-Server接続テスト
func TestDesktopServerConnection(t *testing.T) {
	// .envファイルの読み込み
	if err := godotenv.Load("../../.env"); err != nil {
		t.Logf("Warning: .env file not found: %v", err)
	}

	// desktop-serverのアドレス取得
	host := os.Getenv("DESKTOP_SERVER_HOST")
	if host == "" {
		host = "localhost"
	}
	port := os.Getenv("DESKTOP_SERVER_PORT")
	if port == "" {
		port = "8080"
	}

	addr := fmt.Sprintf("%s:%s", host, port)
	t.Logf("Connecting to desktop-server at %s", addr)

	// クライアント作成
	dsClient, err := client.NewDesktopServerClient(addr)
	if err != nil {
		t.Fatalf("Failed to connect to desktop-server: %v", err)
	}
	defer dsClient.Close()

	// テーブル一覧取得
	ctx := context.Background()
	tables, err := dsClient.GetTables(ctx)
	if err != nil {
		t.Fatalf("Failed to get tables: %v", err)
	}

	t.Logf("Found %d tables", len(tables))
	for i, table := range tables {
		if i < 10 { // 最初の10個だけ表示
			t.Logf("  [%d] %s", i+1, table)
		}
	}

	if len(tables) > 10 {
		t.Logf("  ... and %d more tables", len(tables)-10)
	}
}

// Desktop-Serverからデータ取得テスト
func TestDesktopServerQueryData(t *testing.T) {
	if testing.Short() {
		t.Skip("Skipping desktop-server query test in short mode")
	}

	// .envファイルの読み込み
	if err := godotenv.Load("../../.env"); err != nil {
		log.Printf("Warning: .env file not found: %v", err)
	}

	// desktop-serverのアドレス取得
	host := os.Getenv("DESKTOP_SERVER_HOST")
	if host == "" {
		host = "localhost"
	}
	port := os.Getenv("DESKTOP_SERVER_PORT")
	if port == "" {
		port = "8080"
	}

	addr := fmt.Sprintf("%s:%s", host, port)
	t.Logf("Connecting to desktop-server at %s", addr)

	// クライアント作成
	dsClient, err := client.NewDesktopServerClient(addr)
	if err != nil {
		t.Fatalf("Failed to connect to desktop-server: %v", err)
	}
	defer dsClient.Close()

	ctx := context.Background()

	// dtako_rowsのデータ数確認
	resp, err := dsClient.QueryDatabase(ctx, "SELECT COUNT(*) as cnt FROM dtako_rows")
	if err != nil {
		t.Fatalf("Failed to query dtako_rows: %v", err)
	}
	if len(resp.Rows) > 0 {
		t.Logf("dtako_rows count: %s", resp.Rows[0].Columns["cnt"])
	}

	// dtako_eventsのデータ数確認
	resp, err = dsClient.QueryDatabase(ctx, "SELECT COUNT(*) as cnt FROM dtako_events")
	if err != nil {
		t.Fatalf("Failed to query dtako_events: %v", err)
	}
	if len(resp.Rows) > 0 {
		t.Logf("dtako_events count: %s", resp.Rows[0].Columns["cnt"])
	}

	// etc_meisaiのデータ数確認
	resp, err = dsClient.QueryDatabase(ctx, "SELECT COUNT(*) as cnt FROM etc_meisai")
	if err != nil {
		t.Fatalf("Failed to query etc_meisai: %v", err)
	}
	if len(resp.Rows) > 0 {
		t.Logf("etc_meisai count: %s", resp.Rows[0].Columns["cnt"])
	}

	// dtako_uriage_keihiのデータ数確認
	resp, err = dsClient.QueryDatabase(ctx, "SELECT COUNT(*) as cnt FROM dtako_uriage_keihi")
	if err != nil {
		t.Logf("Warning: dtako_uriage_keihi query failed: %v", err)
	} else if len(resp.Rows) > 0 {
		t.Logf("dtako_uriage_keihi count: %s", resp.Rows[0].Columns["cnt"])
	}
}

// Desktop-Serverから特定の運行データ取得テスト
func TestDesktopServerGetRowData(t *testing.T) {
	if testing.Short() {
		t.Skip("Skipping desktop-server row data test in short mode")
	}

	// .envファイルの読み込み
	if err := godotenv.Load("../../.env"); err != nil {
		log.Printf("Warning: .env file not found: %v", err)
	}

	// desktop-serverのアドレス取得
	host := os.Getenv("DESKTOP_SERVER_HOST")
	if host == "" {
		host = "localhost"
	}
	port := os.Getenv("DESKTOP_SERVER_PORT")
	if port == "" {
		port = "8080"
	}

	addr := fmt.Sprintf("%s:%s", host, port)
	t.Logf("Connecting to desktop-server at %s", addr)

	// クライアント作成
	dsClient, err := client.NewDesktopServerClient(addr)
	if err != nil {
		t.Fatalf("Failed to connect to desktop-server: %v", err)
	}
	defer dsClient.Close()

	ctx := context.Background()

	// 最新の運行データを1件取得
	sql := "SELECT * FROM dtako_rows ORDER BY 出庫日時 DESC LIMIT 1"
	resp, err := dsClient.QueryDatabase(ctx, sql)
	if err != nil {
		t.Fatalf("Failed to query dtako_rows: %v", err)
	}

	if len(resp.Rows) == 0 {
		t.Fatal("No data found in dtako_rows")
	}

	row := resp.Rows[0]
	rowID := row.Columns["id"]
	unkoNo := row.Columns["運行NO"]

	t.Logf("\n=== 運行データ ===")
	t.Logf("ID: %s", rowID)
	t.Logf("運行NO: %s", unkoNo)
	t.Logf("車輌CC: %s", row.Columns["車輌CC"])
	t.Logf("乗務員CD1: %s", row.Columns["乗務員CD1"])
	t.Logf("出庫日時: %s", row.Columns["出庫日時"])

	// この運行に紐づくイベントを取得
	sql = "SELECT COUNT(*) as cnt FROM dtako_events WHERE 運行NO = ?"
	resp, err = dsClient.QueryDatabase(ctx, sql, unkoNo)
	if err != nil {
		t.Errorf("Failed to query events: %v", err)
	} else if len(resp.Rows) > 0 {
		t.Logf("イベント数: %s", resp.Rows[0].Columns["cnt"])
	}

	// ETC明細を取得
	sql = "SELECT COUNT(*) as cnt FROM etc_meisai WHERE dtako_row_id = ?"
	resp, err = dsClient.QueryDatabase(ctx, sql, rowID)
	if err != nil {
		t.Errorf("Failed to query etc_meisai: %v", err)
	} else if len(resp.Rows) > 0 {
		t.Logf("ETC明細数: %s", resp.Rows[0].Columns["cnt"])
	}

	// 売上経費を取得
	sql = "SELECT COUNT(*) as cnt FROM dtako_uriage_keihi WHERE dtako_row_id = ?"
	resp, err = dsClient.QueryDatabase(ctx, sql, rowID)
	if err != nil {
		t.Logf("Warning: dtako_uriage_keihi query failed: %v", err)
	} else if len(resp.Rows) > 0 {
		t.Logf("売上経費数: %s", resp.Rows[0].Columns["cnt"])
	}
}
