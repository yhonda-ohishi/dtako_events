# dtako_events - Go gRPCサービス

デジタルタコグラフ（DTako）のイベントデータと運行データを管理するgRPCベースのマイクロサービス。

## 機能

### 実装済み

- **GetRowDetail (view機能)**: 運行データの詳細取得
  - 運行データ本体
  - イベントデータ
  - 積み・降しペア
  - 前後の運行データ
  - ETCデータ
  - フェリーデータ
  - 一番星データ
  - 経費データ
  - 売上経費データ
  - 料費データ
  - 燃料単価

### 今後実装予定

- CSVインポート機能（autoload）
- 位置情報処理（ジオコーディング）
- 一番星データチェック
- 料費データ処理

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

### grpcurlを使用したview機能の呼び出し

```bash
# GetRowDetail - 運行データ詳細取得
grpcurl -plaintext -d '{
  "id": "202112010001"
}' localhost:50052 dtako.DtakoRowService/GetRowDetail
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

    client := pb.NewDtakoRowServiceClient(conn)

    // 運行データ詳細取得
    resp, err := client.GetRowDetail(context.Background(), &pb.GetRowDetailRequest{
        Id: "202112010001",
    })
    if err != nil {
        log.Fatal(err)
    }

    log.Printf("DtakoRow: %+v", resp.DtakoRow)
    log.Printf("Events: %d", len(resp.Events))
    log.Printf("TsumiOroshi Pairs: %d", len(resp.TsumiOroshiPairs))
}
```

## プロジェクト構造

```
dtako_events/
├── proto/                      # Protocol Buffers定義
│   ├── dtako_events.proto
│   ├── dtako_rows.proto
│   └── (生成されたファイル)
├── internal/
│   ├── models/                # GORMモデル
│   │   ├── dtako_event.go
│   │   ├── dtako_row.go
│   │   ├── ryohi_row.go
│   │   └── ichiban.go
│   ├── repository/            # データアクセス層
│   │   ├── dtako_event_repository.go
│   │   ├── dtako_row_repository.go
│   │   ├── ichiban_repository.go
│   │   └── fuel_tanka_repository.go
│   ├── service/               # gRPCサービス実装
│   │   └── dtako_row_service.go
│   └── config/                # 設定管理
│       └── database.go
├── cmd/
│   └── server/
│       └── main.go           # エントリポイント
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
