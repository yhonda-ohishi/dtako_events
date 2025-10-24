package registry

import (
	"context"
	"log"

	"github.com/yhonda-ohishi/dtako_events/internal/service"
	pb "github.com/yhonda-ohishi/dtako_events/proto"
	dbgrpc "github.com/yhonda-ohishi/db_service/src/proto"
	"google.golang.org/grpc"
	"google.golang.org/grpc/credentials/insecure"
)

// Register dtako_eventsサービスをgRPCサーバーに登録
//
// 動作モード:
// 1. Desktop-server統合モード (dbServer が渡された場合):
//    - RegisterWithServer() を内部で呼び出し
//    - DtakoEventService のみ登録（Db_DTakoEventsService は重複回避）
//    - localServerClient アダプターで同一プロセス内直接呼び出し
//
// 2. Standaloneモード (dbServer が渡されない場合):
//    - 外部db_service (localhost:50051) に接続
//    - DtakoEventService を登録
//
// desktop-server での使用例:
//   dbRegistry := registry.Register(grpcSrv, registry.WithExcludeServices("DTakoEventsService", "DTakoRowsService"))
//   if dbRegistry != nil && dbRegistry.DTakoEventsService != nil {
//       dtakoeventsregistry.Register(grpcSrv, dbRegistry.DTakoEventsService)
//   }
func Register(grpcServer *grpc.Server, dbServer ...dbgrpc.Db_DTakoEventsServiceServer) error {
	if len(dbServer) > 0 && dbServer[0] != nil {
		// Desktop-server統合モード
		log.Println("Registering dtako_events service (desktop-server integration mode)...")
		RegisterWithServer(grpcServer, dbServer[0])
		log.Println("dtako_events service registered successfully (DtakoEventService only)")
		return nil
	}

	// Standaloneモード
	log.Println("Registering dtako_events service (standalone mode)...")

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

// RegisterWithServer db_serviceサーバー実装を使ってDtakoEventServiceを登録
//
// desktop-serverから呼び出され、同一プロセス内のdb_serviceサーバー実装を使用する。
// アダプターにより、サーバー実装をクライアントインターフェースとして扱う。
//
// 登録されるサービス:
//   - DtakoEventService: イベントデータ管理（集計・フィルタリング）
//
// データアクセス:
//   - 同一プロセス内のdb_serviceサーバー実装を直接呼び出し
func RegisterWithServer(grpcServer *grpc.Server, dbServer dbgrpc.Db_DTakoEventsServiceServer) {
	log.Println("Registering dtako_events service with db_service server...")

	// アダプターを使ってサーバー実装をクライアントに変換
	dbClient := &localServerClient{server: dbServer}

	// db_serviceクライアントを使ってサービス作成
	svc := service.NewDtakoEventServiceWithClient(dbClient)
	pb.RegisterDtakoEventServiceServer(grpcServer, svc)

	log.Println("dtako_events service registered successfully (with local server)")
}

// localServerClient はサーバー実装をクライアントインターフェースに変換するアダプター
// 同一プロセス内で直接メソッドを呼び出すため、gRPCのシリアライゼーションオーバーヘッドを回避
type localServerClient struct {
	server dbgrpc.Db_DTakoEventsServiceServer
}

func (c *localServerClient) Get(ctx context.Context, req *dbgrpc.Db_GetDTakoEventsRequest, opts ...grpc.CallOption) (*dbgrpc.Db_DTakoEventsResponse, error) {
	return c.server.Get(ctx, req)
}

func (c *localServerClient) List(ctx context.Context, req *dbgrpc.Db_ListDTakoEventsRequest, opts ...grpc.CallOption) (*dbgrpc.Db_ListDTakoEventsResponse, error) {
	return c.server.List(ctx, req)
}

func (c *localServerClient) GetByOperationNo(ctx context.Context, req *dbgrpc.Db_GetDTakoEventsByOperationNoRequest, opts ...grpc.CallOption) (*dbgrpc.Db_ListDTakoEventsResponse, error) {
	return c.server.GetByOperationNo(ctx, req)
}
