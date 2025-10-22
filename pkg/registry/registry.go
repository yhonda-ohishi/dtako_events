package registry

import (
	"log"

	"github.com/yhonda-ohishi/dtako_events/internal/service"
	pb "github.com/yhonda-ohishi/dtako_events/proto"
	"google.golang.org/grpc"
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
//   - db_service経由で行う（同一プロセス内gRPC呼び出し）
//   - db_serviceがDB操作を担当し、このサービスはビジネスロジックのみ
func Register(grpcServer *grpc.Server) error {
	log.Println("Registering dtako_events service...")

	// ビジネスロジックサービスのみ登録（DB接続不要）
	svc := service.NewDtakoEventService()
	pb.RegisterDtakoEventServiceServer(grpcServer, svc)

	log.Println("dtako_events service registered successfully")
	return nil
}
