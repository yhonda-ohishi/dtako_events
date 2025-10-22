package main

import (
	"fmt"
	"log"
	"net"
	"os"
	"os/signal"
	"syscall"

	"github.com/joho/godotenv"
	"github.com/yhonda-ohishi/dtako_events/internal/config"
	"github.com/yhonda-ohishi/dtako_events/internal/repository"
	"github.com/yhonda-ohishi/dtako_events/internal/service"
	pb "github.com/yhonda-ohishi/dtako_events/proto"
	"google.golang.org/grpc"
	"google.golang.org/grpc/reflection"
)

func main() {
	// .envファイルの読み込み
	if err := godotenv.Load(); err != nil {
		log.Println("Warning: .env file not found, using environment variables")
	}

	// データベース接続
	dbConfig := config.LoadDatabaseConfig()
	db, err := config.ConnectDatabase(dbConfig)
	if err != nil {
		log.Fatalf("Failed to connect to database: %v", err)
	}
	log.Println("Connected to main database")

	// リポジトリ初期化
	eventRepo := repository.NewDtakoEventRepository(db)

	// サービス初期化
	dtakoEventService := service.NewDtakoEventService(eventRepo)

	// gRPCサーバー作成
	grpcServer := grpc.NewServer()

	// サービス登録
	pb.RegisterDtakoEventServiceServer(grpcServer, dtakoEventService)

	// リフレクション登録（grpcurlなどのツール用）
	reflection.Register(grpcServer)

	// ポート設定
	port := os.Getenv("GRPC_PORT")
	if port == "" {
		port = "50052"
	}

	// リスナー作成
	listener, err := net.Listen("tcp", fmt.Sprintf(":%s", port))
	if err != nil {
		log.Fatalf("Failed to listen: %v", err)
	}

	// シグナルハンドリング（Graceful Shutdown）
	sigChan := make(chan os.Signal, 1)
	signal.Notify(sigChan, os.Interrupt, syscall.SIGTERM)

	go func() {
		<-sigChan
		log.Println("Received shutdown signal, stopping server...")
		grpcServer.GracefulStop()
	}()

	// サーバー起動
	log.Printf("Starting gRPC server on port %s...", port)
	log.Printf("Services registered:")
	log.Printf("  - DtakoEventService")

	if err := grpcServer.Serve(listener); err != nil {
		log.Fatalf("Failed to serve: %v", err)
	}
}
