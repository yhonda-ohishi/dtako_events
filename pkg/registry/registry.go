package registry

import (
	"log"

	"github.com/yhonda-ohishi/dtako_events/internal/service"
	pb "github.com/yhonda-ohishi/dtako_events/proto"
	dbgrpc "github.com/yhonda-ohishi/db_service/src/proto"
	"google.golang.org/grpc"
	"google.golang.org/grpc/credentials/insecure"
)

// RegisterWithClient db_serviceクライアントを使ってDtakoEventServiceを登録
//
// desktop-serverから呼び出され、既存のdb_serviceクライアントを使用する。
// このパターンにより、同一プロセス内でdb_serviceクライアントを共有できる。
//
// 登録されるサービス:
//   - DtakoEventService: イベントデータ管理（集計・フィルタリング）
//
// データアクセス:
//   - 渡されたdb_serviceクライアント経由で行う
func RegisterWithClient(grpcServer *grpc.Server, dbClient dbgrpc.Db_DTakoEventsServiceClient) {
	log.Println("Registering dtako_events service with db_service client...")

	// db_serviceクライアントを使ってサービス作成
	svc := service.NewDtakoEventServiceWithClient(dbClient)
	pb.RegisterDtakoEventServiceServer(grpcServer, svc)

	log.Println("dtako_events service registered successfully")
}

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

	// Create db_service client
	conn, err := grpc.NewClient("localhost:50051", grpc.WithTransportCredentials(insecure.NewCredentials()))
	if err != nil {
		log.Printf("Failed to create db_service client: %v", err)
		return err
	}

	dbClient := dbgrpc.NewDb_DTakoEventsServiceClient(conn)

	// Register service using RegisterWithClient
	RegisterWithClient(grpcServer, dbClient)

	log.Println("dtako_events service registered successfully")
	return nil
}
