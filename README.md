# dtako_events - Go gRPCサービス

デジタルタコグラフ（DTako）のイベントデータを管理するgRPCベースのサービス。

**注意**: 運行データ（dtako_rows）は別リポジトリで管理されています。

## アーキテクチャ

このサービスは2つの動作モードをサポートします:

1. **スタンドアロンモード**: 独立したgRPCサーバーとして起動
2. **レジストリモード**: desktop-serverに統合され、単一プロセス内で動作

### レジストリパターンによる統合

desktop-serverに統合する場合は、`pkg/registry.Register()`を使用:

```go
import dtakoevents "github.com/yhonda-ohishi/dtako_events/pkg/registry"

// desktop-server内でサービス登録
dtakoevents.Register(grpcServer, db)
```

## 機能

### 実装済み

- **イベント基本操作**
  - CreateEvent: イベント作成
  - GetEvent: イベント取得
  - UpdateEvent: イベント更新
  - DeleteEvent: イベント削除
  - ListEvents: イベント一覧取得

### 今後実装予定

- FindEmptyLocation: 位置情報が空のイベント検索
- SearchByDateRange: 日付範囲で検索
- SearchByDriver: 運転手で検索
- SetLocationByGeo: 位置情報を設定
- SetGeoCode: ジオコーディング
- CSVインポート機能（autoload）

## セットアップ

### 必要要件

- Go 1.21以上
- MySQL/MariaDB
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
# データベース設定
DB_HOST=localhost
DB_PORT=3306
DB_USER=your_username
DB_PASSWORD=your_password
DB_NAME=dtako_db

# 一番星データベース設定
ICHIBAN_DB_HOST=localhost
ICHIBAN_DB_PORT=3306
ICHIBAN_DB_USER=your_username
ICHIBAN_DB_PASSWORD=your_password
ICHIBAN_DB_NAME=ichiban_db

# gRPC設定
GRPC_PORT=50052
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
# GetEvent - イベント取得
grpcurl -plaintext -d '{
  "srch_id": "event_001"
}' localhost:50052 dtako.DtakoEventService/GetEvent

# ListEvents - イベント一覧取得
grpcurl -plaintext -d '{
  "page": 1,
  "page_size": 10
}' localhost:50052 dtako.DtakoEventService/ListEvents
```

### Goクライアントでの使用例

```go
package main

import (
    "context"
    "log"

    pb "github.com/yhonda-ohishi/dtako_events/proto"
    "google.golang.org/grpc"
)

func main() {
    conn, err := grpc.Dial("localhost:50052", grpc.WithInsecure())
    if err != nil {
        log.Fatal(err)
    }
    defer conn.Close()

    client := pb.NewDtakoEventServiceClient(conn)

    // イベント取得
    resp, err := client.GetEvent(context.Background(), &pb.GetEventRequest{
        SrchId: "event_001",
    })
    if err != nil {
        log.Fatal(err)
    }

    log.Printf("Event: %+v", resp)
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
│   ├── models/                     # GORMモデル
│   │   ├── dtako_event.go          # イベントモデル
│   │   ├── driver.go               # 運転手モデル
│   │   └── ryohi_row.go            # 料費モデル
│   ├── repository/                 # データアクセス層
│   │   └── dtako_event_repository.go
│   ├── service/                    # gRPCサービス実装
│   │   └── dtako_event_service.go
│   └── config/                     # 設定管理
│       └── database.go
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

### データベース接続エラー

1. `.env`ファイルの設定を確認
2. MySQLサーバーが起動しているか確認
3. データベース名とユーザー権限を確認

### Protocol Buffersのコンパイルエラー

```bash
# protocのインストール確認
protoc --version

# Go用プラグインのインストール
go install google.golang.org/protobuf/cmd/protoc-gen-go@latest
go install google.golang.org/grpc/cmd/protoc-gen-go-grpc@latest
```

## ライセンス

MIT License

## 作成者

yhonda-ohishi
