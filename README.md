# dtako_events - Go gRPCサービス

デジタルタコグラフ（DTako）のイベントデータを管理する**読み取り専用**gRPCサービス。

**注意**:
- 運行データ（dtako_rows）は別リポジトリで管理されています
- データアクセスは全て[db_service](https://github.com/yhonda-ohishi/db_service)経由で行います

## アーキテクチャ

このサービスは2つの動作モードをサポートします:

1. **スタンドアロンモード**: 独立したgRPCサーバーとして起動（ポート50052）
2. **レジストリモード**: desktop-serverに統合され、単一プロセス内で動作（推奨）

### レジストリパターンによる統合

desktop-serverに統合する場合は、`pkg/registry.Register()`を使用:

```go
import dtakoevents "github.com/yhonda-ohishi/dtako_events/pkg/registry"

// desktop-server内でサービス登録（DB接続不要）
dtakoevents.Register(grpcServer)
```

### データアクセス

- **db_service経由**: 全てのデータアクセスはdb_service（localhost:50051）経由
- **読み取り専用**: Create/Update/Delete操作は提供しません
- **ビジネスロジック層**: このサービスは集計やフィルタリングなどのビジネスロジックのみ担当

## 機能

### 実装済み

- **GetEvent**: ID指定でイベント取得
- **GetByUnkoNo**: 運行NO指定でイベント一覧取得（時刻フィルタ対応）
- **AggregateByEventType**: イベント種別ごとの集計
  - 件数、時間（分）、区間距離、走行距離の合計・平均
  - 全イベント種別の総合計も含む

### 今後実装予定

- イベント検索の高度なフィルタリング
- 運転手・車両別の集計
- 時系列分析

## セットアップ

### 必要要件

- Go 1.25.1以上
- [db_service](https://github.com/yhonda-ohishi/db_service)が起動していること（localhost:50051）
- Protocol Buffers Compiler (protoc)

### インストール

```bash
# リポジトリクローン
git clone https://github.com/yhonda-ohishi/dtako_events.git
cd dtako_events

# 依存関係のインストール
go mod download

# Protocol Buffersのコンパイル
make proto
```

### 環境設定

`.env.example`を`.env`にコピーして編集:

```bash
cp .env.example .env
```

```.env
# gRPC設定
GRPC_PORT=50052

# db_service接続設定（同一プロセス内またはローカルホスト）
DB_SERVICE_HOST=localhost
DB_SERVICE_PORT=50051

# ログレベル (debug, info, warn, error)
LOG_LEVEL=info
```

## ビルドと実行

```bash
# ビルド
make build

# 実行
make run

# または直接実行
./bin/dtako_events
```

## API使用例

### grpcurlを使用したイベント操作

```bash
# GetEvent - ID指定でイベント取得
grpcurl -plaintext -d '{
  "id": 12345
}' localhost:50052 dtako_events.DtakoEventService/GetEvent

# GetByUnkoNo - 運行NO指定でイベント一覧取得
grpcurl -plaintext -d '{
  "unko_no": "202510220001"
}' localhost:50052 dtako_events.DtakoEventService/GetByUnkoNo

# GetByUnkoNo - 時刻フィルタ付き
grpcurl -plaintext -d '{
  "unko_no": "202510220001",
  "start_time": "2025-10-22T00:00:00Z",
  "end_time": "2025-10-22T23:59:59Z"
}' localhost:50052 dtako_events.DtakoEventService/GetByUnkoNo

# AggregateByEventType - イベント種別ごとの集計
grpcurl -plaintext -d '{
  "unko_no": "202510220001"
}' localhost:50052 dtako_events.DtakoEventService/AggregateByEventType
```

### Goクライアントでの使用例

```go
package main

import (
    "context"
    "log"

    pb "github.com/yhonda-ohishi/dtako_events/proto"
    "google.golang.org/grpc"
    "google.golang.org/grpc/credentials/insecure"
)

func main() {
    conn, err := grpc.Dial("localhost:50052",
        grpc.WithTransportCredentials(insecure.NewCredentials()))
    if err != nil {
        log.Fatal(err)
    }
    defer conn.Close()

    client := pb.NewDtakoEventServiceClient(conn)

    // 運行NOでイベント取得
    resp, err := client.GetByUnkoNo(context.Background(), &pb.GetByUnkoNoRequest{
        UnkoNo: "202510220001",
    })
    if err != nil {
        log.Fatal(err)
    }

    log.Printf("Found %d events", len(resp.Events))

    // イベント種別ごとの集計
    agg, err := client.AggregateByEventType(context.Background(), &pb.AggregateByEventTypeRequest{
        UnkoNo: "202510220001",
    })
    if err != nil {
        log.Fatal(err)
    }

    log.Printf("Total events: %d", agg.Total.Count)
    for _, a := range agg.Aggregates {
        log.Printf("%s: %d件, 平均時間%.1f分",
            a.EventType, a.Count, a.AvgDurationMinutes)
    }
}
```

## プロジェクト構造

```
dtako_events/
├── proto/                          # Protocol Buffers定義
│   ├── dtako_events.proto          # イベントサービス定義
│   └── (生成されたファイル)
├── pkg/
│   └── registry/                   # レジストリパターン実装
│       └── registry.go             # desktop-server統合用
├── internal/
│   └── service/                    # gRPCサービス実装（ビジネスロジック）
│       └── dtako_event_service.go  # db_serviceクライアント経由でデータアクセス
├── cmd/
│   └── server/
│       └── main.go                 # エントリポイント（スタンドアロンモード）
├── .env.example
├── Makefile
├── go.mod
└── README.md
```

## 開発

### Protocol Buffersの再コンパイル

```bash
make proto
```

### テスト実行

```bash
make test

# カバレッジ付き
make test-coverage
```

### クリーンアップ

```bash
make clean
```

## トラブルシューティング

### db_service接続エラー

1. db_serviceが起動しているか確認
   ```bash
   # db_serviceの起動確認
   grpcurl -plaintext localhost:50051 list
   ```
2. `.env`ファイルのDB_SERVICE_HOST/PORTを確認
3. desktop-server統合時は同一プロセス内で動作するため接続不要

### Protocol Buffersのコンパイルエラー

```bash
# protocのインストール確認
protoc --version

# Go用プラグインのインストール
go install google.golang.org/protobuf/cmd/protoc-gen-go@latest
go install google.golang.org/grpc/cmd/protoc-gen-go-grpc@latest
```

## バージョン履歴

### v1.1.0 (2025-10-22)
- db_service統合による読み取り専用サービス化
- リポジトリ層削除、db_service経由でデータアクセス
- イベント種別ごとの集計機能追加
- 時刻フィルタ付き運行NO検索

### v1.0.0 (2025-10-22)
- 初回リリース
- 基本的なイベント取得機能

## ライセンス

MIT License

## 作成者

yhonda-ohishi
