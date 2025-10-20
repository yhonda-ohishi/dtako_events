package integration

import (
	"fmt"
	"log"
	"os"
	"testing"

	"github.com/joho/godotenv"
	"gorm.io/driver/mysql"
	"gorm.io/gorm"
)

// prod_dbへの直接接続テスト
func TestProdDBConnection(t *testing.T) {
	if testing.Short() {
		t.Skip("Skipping prod_db test in short mode")
	}

	// .envファイルの読み込み
	if err := godotenv.Load("../../.env"); err != nil {
		t.Logf("Warning: .env file not found: %v", err)
	}

	// データベース設定取得
	host := os.Getenv("DB_HOST")
	port := os.Getenv("DB_PORT")
	user := os.Getenv("DB_USER")
	password := os.Getenv("DB_PASSWORD")
	dbname := os.Getenv("DB_NAME")

	if host == "" || user == "" || password == "" || dbname == "" {
		t.Skip("Database credentials not configured, skipping test")
	}

	dsn := fmt.Sprintf("%s:%s@tcp(%s:%s)/%s?charset=utf8mb4&parseTime=True&loc=Local",
		user, password, host, port, dbname)

	t.Logf("Connecting to database: %s@%s:%s/%s", user, host, port, dbname)

	// データベース接続
	db, err := gorm.Open(mysql.Open(dsn), &gorm.Config{})
	if err != nil {
		t.Fatalf("Failed to connect to database: %v", err)
	}

	sqlDB, err := db.DB()
	if err != nil {
		t.Fatalf("Failed to get database instance: %v", err)
	}
	defer sqlDB.Close()

	// 接続確認
	if err := sqlDB.Ping(); err != nil {
		t.Fatalf("Failed to ping database: %v", err)
	}

	t.Log("Successfully connected to prod_db")

	// テーブル一覧を取得
	var tables []string
	result := db.Raw("SHOW TABLES").Scan(&tables)
	if result.Error != nil {
		t.Fatalf("Failed to get tables: %v", result.Error)
	}

	t.Logf("Found %d tables in database", len(tables))
	for i, table := range tables {
		if i < 10 { // 最初の10件だけ表示
			t.Logf("  - %s", table)
		}
	}

	// dtako_rowsテーブルのレコード数確認
	var count int64
	if err := db.Table("dtako_rows").Count(&count).Error; err != nil {
		t.Logf("Warning: Failed to count dtako_rows: %v", err)
	} else {
		t.Logf("dtako_rows table has %d records", count)
	}

	// dtako_eventsテーブルのレコード数確認
	if err := db.Table("dtako_events").Count(&count).Error; err != nil {
		t.Logf("Warning: Failed to count dtako_events: %v", err)
	} else {
		t.Logf("dtako_events table has %d records", count)
	}
}

// 簡単なデータ取得テスト
func TestProdDBQueryData(t *testing.T) {
	if testing.Short() {
		t.Skip("Skipping prod_db query test in short mode")
	}

	// .envファイルの読み込み
	if err := godotenv.Load("../../.env"); err != nil {
		log.Printf("Warning: .env file not found: %v", err)
	}

	// データベース設定取得
	host := os.Getenv("DB_HOST")
	port := os.Getenv("DB_PORT")
	user := os.Getenv("DB_USER")
	password := os.Getenv("DB_PASSWORD")
	dbname := os.Getenv("DB_NAME")

	if host == "" || user == "" || password == "" || dbname == "" {
		t.Skip("Database credentials not configured, skipping test")
	}

	dsn := fmt.Sprintf("%s:%s@tcp(%s:%s)/%s?charset=utf8mb4&parseTime=True&loc=Local",
		user, password, host, port, dbname)

	// データベース接続
	db, err := gorm.Open(mysql.Open(dsn), &gorm.Config{})
	if err != nil {
		t.Fatalf("Failed to connect to database: %v", err)
	}

	sqlDB, err := db.DB()
	if err != nil {
		t.Fatalf("Failed to get database instance: %v", err)
	}
	defer sqlDB.Close()

	// dtako_rowsから最新1件取得
	type DtakoRowSample struct {
		ID        string `gorm:"column:id"`
		UnkoNo    string `gorm:"column:運行NO"`
		SharyouCC string `gorm:"column:車輌CC"`
		JomuinCD1 string `gorm:"column:乗務員CD1"`
	}

	var row DtakoRowSample
	result := db.Table("dtako_rows").
		Order("出庫日時 DESC").
		First(&row)

	if result.Error != nil {
		if result.Error == gorm.ErrRecordNotFound {
			t.Log("No records found in dtako_rows")
		} else {
			t.Fatalf("Failed to query dtako_rows: %v", result.Error)
		}
	} else {
		t.Logf("Latest dtako_row:")
		t.Logf("  ID: %s", row.ID)
		t.Logf("  運行NO: %s", row.UnkoNo)
		t.Logf("  車輌CC: %s", row.SharyouCC)
		t.Logf("  乗務員CD1: %s", row.JomuinCD1)
	}

	// dtako_eventsから最新5件取得
	type DtakoEventSample struct {
		SrchID    string `gorm:"column:srch_id"`
		EventName string `gorm:"column:イベント名"`
		UnkoNo    string `gorm:"column:運行NO"`
	}

	var events []DtakoEventSample
	result = db.Table("dtako_events").
		Order("開始日時 DESC").
		Limit(5).
		Find(&events)

	if result.Error != nil {
		t.Fatalf("Failed to query dtako_events: %v", result.Error)
	}

	t.Logf("Latest 5 dtako_events:")
	for i, event := range events {
		t.Logf("  %d. %s - %s (運行NO: %s)", i+1, event.SrchID, event.EventName, event.UnkoNo)
	}
}

// ETCMeisaiテーブルの確認
func TestProdDBETCMeisai(t *testing.T) {
	if testing.Short() {
		t.Skip("Skipping prod_db ETC test in short mode")
	}

	// .envファイルの読み込み
	if err := godotenv.Load("../../.env"); err != nil {
		log.Printf("Warning: .env file not found: %v", err)
	}

	// データベース設定取得
	host := os.Getenv("DB_HOST")
	port := os.Getenv("DB_PORT")
	user := os.Getenv("DB_USER")
	password := os.Getenv("DB_PASSWORD")
	dbname := os.Getenv("DB_NAME")

	if host == "" || user == "" || password == "" || dbname == "" {
		t.Skip("Database credentials not configured, skipping test")
	}

	dsn := fmt.Sprintf("%s:%s@tcp(%s:%s)/%s?charset=utf8mb4&parseTime=True&loc=Local",
		user, password, host, port, dbname)

	db, err := gorm.Open(mysql.Open(dsn), &gorm.Config{})
	if err != nil {
		t.Fatalf("Failed to connect to database: %v", err)
	}

	sqlDB, err := db.DB()
	if err != nil {
		t.Fatalf("Failed to get database instance: %v", err)
	}
	defer sqlDB.Close()

	// etc_meisaiテーブルの確認
	var count int64
	if err := db.Table("etc_meisai").Count(&count).Error; err != nil {
		t.Logf("etc_meisai table may not exist: %v", err)
		t.Skip("etc_meisai table not available")
	}

	t.Logf("etc_meisai table has %d records", count)

	// 最新5件取得
	type ETCMeisaiSample struct {
		ID         uint   `gorm:"column:id"`
		DtakoRowID string `gorm:"column:dtako_row_id"`
		Kingaku    int    `gorm:"column:kingaku"`
	}

	var etcList []ETCMeisaiSample
	result := db.Table("etc_meisai").
		Order("id DESC").
		Limit(5).
		Find(&etcList)

	if result.Error != nil {
		t.Fatalf("Failed to query etc_meisai: %v", result.Error)
	}

	t.Logf("Latest 5 etc_meisai records:")
	for i, etc := range etcList {
		t.Logf("  %d. ID=%d, DtakoRowID=%s, 金額=%d", i+1, etc.ID, etc.DtakoRowID, etc.Kingaku)
	}
}
