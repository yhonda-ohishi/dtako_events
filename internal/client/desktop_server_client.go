package client

import (
	"context"
	"fmt"
	"time"

	pb "github.com/yhonda-ohishi/dtako_events/proto"
	"google.golang.org/grpc"
	"google.golang.org/grpc/credentials/insecure"
)

// DesktopServerClient desktop-serverへのgRPCクライアント
type DesktopServerClient struct {
	conn   *grpc.ClientConn
	client pb.DatabaseServiceClient
}

// NewDesktopServerClient クライアントを作成
func NewDesktopServerClient(address string) (*DesktopServerClient, error) {
	ctx, cancel := context.WithTimeout(context.Background(), 5*time.Second)
	defer cancel()

	conn, err := grpc.DialContext(ctx, address,
		grpc.WithTransportCredentials(insecure.NewCredentials()),
		grpc.WithBlock(),
	)
	if err != nil {
		return nil, fmt.Errorf("failed to connect to desktop-server: %w", err)
	}

	return &DesktopServerClient{
		conn:   conn,
		client: pb.NewDatabaseServiceClient(conn),
	}, nil
}

// Close 接続をクローズ
func (c *DesktopServerClient) Close() error {
	if c.conn != nil {
		return c.conn.Close()
	}
	return nil
}

// QueryDatabase SQLクエリを実行
func (c *DesktopServerClient) QueryDatabase(ctx context.Context, sql string, params ...string) (*pb.QueryResponse, error) {
	req := &pb.QueryRequest{
		Sql:    sql,
		Params: params,
	}

	resp, err := c.client.QueryDatabase(ctx, req)
	if err != nil {
		return nil, fmt.Errorf("query failed: %w", err)
	}

	return resp, nil
}

// GetTables テーブル一覧を取得
func (c *DesktopServerClient) GetTables(ctx context.Context) ([]string, error) {
	resp, err := c.client.GetTables(ctx, &pb.GetTablesRequest{})
	if err != nil {
		return nil, fmt.Errorf("get tables failed: %w", err)
	}

	return resp.Tables, nil
}

// ExecuteSQL SQL実行（INSERT/UPDATE/DELETE）
func (c *DesktopServerClient) ExecuteSQL(ctx context.Context, sql string, params ...string) (int32, error) {
	req := &pb.ExecuteRequest{
		Sql:    sql,
		Params: params,
	}

	resp, err := c.client.ExecuteSQL(ctx, req)
	if err != nil {
		return 0, fmt.Errorf("execute failed: %w", err)
	}

	return resp.AffectedRows, nil
}
