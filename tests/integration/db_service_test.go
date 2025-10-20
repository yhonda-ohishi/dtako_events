package integration

import (
	"context"
	"fmt"
	"log"
	"os"
	"testing"
	"time"

	"github.com/joho/godotenv"
	"google.golang.org/grpc"
	"google.golang.org/grpc/credentials/insecure"
)

// テスト用のgRPCクライアント設定
func setupDBServiceClient(t *testing.T) *grpc.ClientConn {
	// .envファイルの読み込み
	if err := godotenv.Load("../../.env"); err != nil {
		t.Logf("Warning: .env file not found: %v", err)
	}

	// db_serviceのアドレス取得
	dbServiceHost := os.Getenv("DB_SERVICE_HOST")
	if dbServiceHost == "" {
		dbServiceHost = "localhost"
	}
	dbServicePort := os.Getenv("DB_SERVICE_PORT")
	if dbServicePort == "" {
		dbServicePort = "50051"
	}

	addr := fmt.Sprintf("%s:%s", dbServiceHost, dbServicePort)
	t.Logf("Connecting to db_service at %s", addr)

	// gRPC接続
	ctx, cancel := context.WithTimeout(context.Background(), 5*time.Second)
	defer cancel()

	conn, err := grpc.DialContext(ctx, addr,
		grpc.WithTransportCredentials(insecure.NewCredentials()),
		grpc.WithBlock(),
	)
	if err != nil {
		t.Fatalf("Failed to connect to db_service: %v", err)
	}

	return conn
}

// サービス一覧を確認するテスト
func TestDBServiceConnection(t *testing.T) {
	conn := setupDBServiceClient(t)
	defer conn.Close()

	// 接続成功を確認
	state := conn.GetState()
	t.Logf("Connection state: %v", state)

	if state.String() != "READY" {
		t.Errorf("Expected connection state READY, got %v", state)
	}
}

// このテストは手動実行用（go test -v -run TestDBServiceManual）
func TestDBServiceManual(t *testing.T) {
	if testing.Short() {
		t.Skip("Skipping manual test in short mode")
	}

	// .envファイルの読み込み
	if err := godotenv.Load("../../.env"); err != nil {
		log.Printf("Warning: .env file not found: %v", err)
	}

	// db_serviceのアドレス取得
	dbServiceHost := os.Getenv("DB_SERVICE_HOST")
	if dbServiceHost == "" {
		dbServiceHost = "localhost"
	}
	dbServicePort := os.Getenv("DB_SERVICE_PORT")
	if dbServicePort == "" {
		dbServicePort = "50051"
	}

	addr := fmt.Sprintf("%s:%s", dbServiceHost, dbServicePort)
	log.Printf("Connecting to db_service at %s", addr)

	// gRPC接続
	conn, err := grpc.Dial(addr,
		grpc.WithTransportCredentials(insecure.NewCredentials()),
	)
	if err != nil {
		t.Fatalf("Failed to connect to db_service: %v", err)
	}
	defer conn.Close()

	// 接続状態確認
	state := conn.GetState()
	log.Printf("Connection state: %v", state)

	// ここでdb_serviceのクライアントを使ってデータ取得テストを実施
	// 例: ETCMeisaiService, DTakoUriageKeihiService等

	log.Println("db_service connection test completed successfully")
}
