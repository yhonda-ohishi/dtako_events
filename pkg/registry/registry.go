package registry

import (
	"log"

	"github.com/yhonda-ohishi/dtako_events/internal/repository"
	"github.com/yhonda-ohishi/dtako_events/internal/service"
	pb "github.com/yhonda-ohishi/dtako_events/proto"
	"google.golang.org/grpc"
	"gorm.io/gorm"
)

// Register dtako_eventsサービスをgRPCサーバーに登録
//
// desktop-serverから呼び出され、単一プロセス内でサービス登録を行う。
// このパターンにより、複数のサービスを1つのプロセスで管理できる。
//
// 登録されるサービス:
//   - DtakoEventService: イベントデータ管理
//
// データアクセス:
//   - 全てdesktop-server経由で行う（直接DB接続なし）
//   - desktop-serverが更新されると自動的に最新機能が利用可能
func Register(grpcServer *grpc.Server, db *gorm.DB) error {
	log.Println("Registering dtako_events service...")

	// リポジトリ初期化
	eventRepo := repository.NewDtakoEventRepository(db)

	// サービス初期化
	dtakoEventService := service.NewDtakoEventService(eventRepo)

	// gRPCサービス登録
	pb.RegisterDtakoEventServiceServer(grpcServer, dtakoEventService)

	log.Println("dtako_events service registered successfully")
	return nil
}
