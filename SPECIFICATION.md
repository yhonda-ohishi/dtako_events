# dtako_events - Go gRPCサービス仕様書

## 概要

デジタルタコグラフ（DTako）のイベントデータと運行データを管理するgRPCベースのマイクロサービス。
CakePHP版の`DtakoEventsController.php`をGoで再実装し、gRPCによる高性能なデータ処理とAPI提供を実現する。

## 技術スタック

- **言語**: Go 1.21+
- **プロトコル**: gRPC + gRPC-Web
- **データベース**: MySQL/MariaDB
- **ORM**: GORM v1.25+
- **外部連携**:
  - [db_service](https://github.com/yhonda-ohishi/db_service) - データベースリポジトリサービス
  - [desktop-server](https://github.com/yhonda-ohishi-pub-dev/desktop-server) - デスクトップクライアント
  - [etc_data_processor](https://github.com/yhonda-ohishi/etc_data_processor) - ETCデータ処理

## アーキテクチャ

### 参考アーキテクチャ

#### desktop-server（クライアント統合パターン）
```
┌─────────────────────────────────────────────────────┐
│ desktop-server.exe (Single Binary)                  │
├─────────────────────────────────────────────────────┤
│  ┌──────────────┐  ┌─────────────────────────────┐ │
│  │System Tray UI│  │ HTTP Server (localhost:8080)│ │
│  └──────────────┘  ├─────────────────────────────┤ │
│                    │ gRPC-Web Proxy              │ │
│                    │ ↓                           │ │
│                    │ gRPC Server                 │ │
│                    │ ↓                           │ │
│                    │ db_service integration      │ │
│                    └─────────────────────────────┘ │
└─────────────────────────────────────────────────────┘
```

#### db_service（リポジトリパターン）
```
db_service/
├── src/proto/       # Protocol Buffers定義
├── src/models/      # GORMモデル
├── src/repository/  # データアクセス層
├── src/service/     # gRPCサービス実装
└── src/registry/    # サービス自動登録
```

#### etc_data_processor（データ処理パターン）
```
etc_data_processor/
├── src/pkg/handler/  # サービス層とバリデーション
├── src/pkg/parser/   # CSVパーサー
└── src/proto/        # gRPC定義
```

### dtako_events アーキテクチャ

```
dtako_events/
├── proto/
│   ├── dtako_events.proto        # イベントサービス定義
│   ├── dtako_rows.proto          # 運行データサービス定義
│   └── common.proto              # 共通型定義
├── internal/
│   ├── models/                   # GORMモデル
│   │   ├── dtako_event.go       # イベントエンティティ
│   │   ├── dtako_row.go         # 運行データエンティティ
│   │   ├── ryohi_row.go         # 料費データエンティティ
│   │   └── associations.go      # リレーション定義
│   ├── repository/               # データアクセス層
│   │   ├── dtako_event_repo.go
│   │   ├── dtako_row_repo.go
│   │   └── location_repo.go
│   ├── service/                  # gRPCサービス実装
│   │   ├── dtako_event_service.go
│   │   ├── dtako_row_service.go
│   │   ├── csv_import_service.go
│   │   └── location_service.go
│   ├── processor/                # ビジネスロジック
│   │   ├── csv_loader.go        # CSV読み込み処理
│   │   ├── ichiban_checker.go   # 一番星データチェック
│   │   ├── ferry_processor.go   # フェリーデータ処理
│   │   └── etc_processor.go     # ETCデータ処理
│   └── config/                   # 設定管理
│       ├── database.go
│       └── grpc.go
├── pkg/                          # 公開パッケージ
│   └── registry/                 # サービス登録（db_serviceパターン）
│       └── registry.go
├── cmd/
│   └── server/
│       └── main.go              # エントリポイント
├── .env.example
├── Makefile
└── go.mod
```

## データモデル

### 主要エンティティ

#### DtakoEvent（デジタルタコグラフイベント）
```go
type DtakoEvent struct {
    ID              string    `gorm:"primaryKey"`
    EventType       string    // イベント名（開始、終了、アイドリング等）
    UnkoNo          string    // 運行NO
    DriverID        string    // 運転手ID
    StartDateTime   time.Time // 開始日時
    EndDateTime     *time.Time// 終了日時
    StartLatitude   float64   // 開始GPS緯度
    StartLongitude  float64   // 開始GPS経度
    StartCityName   string    // 開始市町村名
    EndLatitude     *float64  // 終了GPS緯度
    EndLongitude    *float64  // 終了GPS経度
    EndCityName     *string   // 終了市町村名
    CreatedAt       time.Time
    UpdatedAt       time.Time
}
```

#### DtakoRow（運行データ）
```go
type DtakoRow struct {
    ID              string    `gorm:"primaryKey"` // 運行NO
    UnkoNo          string    // 運行NO
    Shaban          string    // 車番
    DriverID        string    // 運転手ID
    StartDateTime   time.Time // 出庫日時
    EndDateTime     *time.Time// 帰庫日時
    Distance        float64   // 走行距離
    FuelUsed        float64   // 燃料使用量
    CreatedAt       time.Time
    UpdatedAt       time.Time

    // リレーション
    Events          []DtakoEvent `gorm:"foreignKey:UnkoNo"`
    Driver          Driver       `gorm:"foreignKey:DriverID"`
    FerryRows       []DtakoFerryRow
    KeihiData       []DtakoKeihi
}
```

#### RyohiRow（料費データ）
```go
type RyohiRow struct {
    ID              uint      `gorm:"primaryKey;autoIncrement"`
    UnkoNo          string    // 運行NO
    TsumiDate       string    // 積日
    OroshiDate      string    // 卸日
    Tokuisaki       string    // 得意先
    Status          string    // ステータス
    CreatedAt       time.Time
    UpdatedAt       time.Time
}
```

## gRPCサービス定義

### DtakoEventService

```protobuf
service DtakoEventService {
  // イベント基本操作
  rpc CreateEvent(CreateEventRequest) returns (Event);
  rpc GetEvent(GetEventRequest) returns (Event);
  rpc UpdateEvent(UpdateEventRequest) returns (Event);
  rpc DeleteEvent(DeleteEventRequest) returns (DeleteEventResponse);
  rpc ListEvents(ListEventsRequest) returns (ListEventsResponse);

  // 検索・フィルタリング
  rpc FindEmptyLocation(FindEmptyLocationRequest) returns (ListEventsResponse);
  rpc SearchByDateRange(DateRangeRequest) returns (ListEventsResponse);
  rpc SearchByDriver(DriverSearchRequest) returns (ListEventsResponse);

  // 位置情報処理
  rpc SetLocationByGeo(SetLocationRequest) returns (SetLocationResponse);
  rpc SetGeoCode(SetGeoCodeRequest) returns (SetGeoCodeResponse);
}

service DtakoRowService {
  // 運行データ基本操作
  rpc CreateRow(CreateRowRequest) returns (Row);
  rpc GetRow(GetRowRequest) returns (Row);
  rpc UpdateRow(UpdateRowRequest) returns (Row);
  rpc DeleteRow(DeleteRowRequest) returns (DeleteRowResponse);
  rpc ListRows(ListRowsRequest) returns (ListRowsResponse);

  // 検索
  rpc SearchRows(SearchRowsRequest) returns (ListRowsResponse);
  rpc SearchByShaban(ShabanSearchRequest) returns (ListRowsResponse);
}

service CSVImportService {
  // CSVインポート
  rpc AutoLoad(AutoLoadRequest) returns (AutoLoadResponse);
  rpc ImportCSV(ImportCSVRequest) returns (ImportCSVResponse);
  rpc ProcessFile(ProcessFileRequest) returns (ProcessFileResponse);

  // ストリーミングインポート（大容量対応）
  rpc StreamImport(stream ImportChunk) returns (ImportCSVResponse);
}

service LocationService {
  // ジオコーディング
  rpc ReverseGeocode(GeocodeRequest) returns (GeocodeResponse);
  rpc BulkReverseGeocode(BulkGeocodeRequest) returns (stream GeocodeResponse);
}
```

## 主要機能

### 1. CSVデータインポート（autoload機能）

**元PHP機能**: `autoload()`, `_autoload()`

**Go実装**:
```go
// CSVImportService実装
func (s *CSVImportService) AutoLoad(ctx context.Context, req *pb.AutoLoadRequest) (*pb.AutoLoadResponse, error) {
    // CSVファイル検出
    csvFiles := []string{
        "KUDGURI.csv",  // 運行データ
        "KUDGFRY.csv",  // フェリーデータ
        "KUDGIVT.csv",  // イベントデータ
        "SokudoData.csv", // 速度データ
    }

    // 各ファイルを処理
    for _, filename := range csvFiles {
        processor := processor.NewCSVLoader(filename, s.db)
        if err := processor.Load(); err != nil {
            return nil, err
        }
    }

    // db_serviceとの連携
    // ETCデータ、経費データの同期

    return &pb.AutoLoadResponse{Success: true}, nil
}
```

**処理フロー**:
1. CSVファイル検出（指定ディレクトリから）
2. ファイル種別判定
3. 一時ディレクトリへ移動
4. データパース・バリデーション（etc_data_processorパターン）
5. データベース保存（既存データ削除→新規挿入）
6. 関連データ処理（db_service連携）

### 2. 一番星データ連携（ichiban機能）

**元PHP機能**: `ichibandtakocheck()`, `_ichiban_search()`, `_ichiban_check()`

**Go実装**:
```go
// processor/ichiban_checker.go
type IchibanChecker struct {
    repo repository.DtakoRowRepository
}

func (c *IchibanChecker) Check(date time.Time, shaban string) (*CheckResult, error) {
    // 一番星システムからデータ取得
    // dtako_rowsとのマッチング
    // 差異チェック
}

func (c *IchibanChecker) Search(date time.Time, shaban string) ([]IchibanData, error) {
    // 一番星から出力用データ検索
}
```

### 3. 位置情報処理（ジオコーディング）

**元PHP機能**: `setLocationByGeo()`, `setGeoCode()`, `_setGeoCode()`

**Go実装**:
```go
// service/location_service.go
type LocationService struct {
    repo repository.LocationRepository
    geocoder *Geocoder // Google Maps API等
}

func (s *LocationService) ReverseGeocode(ctx context.Context, req *pb.GeocodeRequest) (*pb.GeocodeResponse, error) {
    // GPS座標から市町村名を取得
    // キャッシュ機構（重複リクエスト削減）
}

func (s *LocationService) BulkReverseGeocode(req *pb.BulkGeocodeRequest, stream pb.LocationService_BulkReverseGeocodeServer) error {
    // 大量データの一括ジオコーディング（ストリーミング）
}
```

### 4. 料費データ処理（dryohi機能）

**元PHP機能**: `dryohi`クラス全体

**Go実装**:
```go
// processor/ryohi_processor.go
type RyohiProcessor struct {
    dtakoRepo repository.DtakoRowRepository
    ryohiRepo repository.RyohiRowRepository
    dbService *db_service.Client // db_service連携
}

func (p *RyohiProcessor) SetRyohiData(ids []string) error {
    // 運行NOから料費データ設定
    // dtako_rowsとの関連付け
    // ETCデータ連携（db_service経由）
    // フェリーデータ連携
}

func (p *RyohiProcessor) SetPeriods(ids []string) error {
    // 積卸期間設定
}

func (p *RyohiProcessor) SetTokui(unkoDate, kubun, oroshiDate, tokuisaki string) error {
    // 得意先設定
}
```

### 5. ETCデータ処理

**元PHP機能**: `_set_etc_data()`

**Go実装**:
```go
// db_serviceのETCMeisaiServiceと連携
func (p *ETCProcessor) SetETCData(ids []string) error {
    // db_serviceのETCMeisaiServiceを呼び出し
    client := p.dbServiceClient.ETCMeisaiService()

    for _, id := range ids {
        resp, err := client.List(ctx, &pb.ListRequest{
            Filter: &pb.Filter{UnkoNo: id},
        })
        // ETCデータとdtako_eventsのマッピング
    }
}
```

### 6. フェリーデータ処理

**元PHP機能**: `_set_ferry()`

**Go実装**:
```go
// db_serviceのDTakoFerryRowsServiceと連携
func (p *FerryProcessor) SetFerryData(ids []string) error {
    // db_serviceのDTakoFerryRowsServiceを呼び出し
    client := p.dbServiceClient.DTakoFerryRowsService()

    // フェリー運行データ設定
}
```

## db_service連携パターン

### 統合方法（desktop-serverパターンを踏襲）

```go
// cmd/server/main.go
import (
    "google.golang.org/grpc"
    dtako_registry "github.com/yhonda-ohishi/dtako_events/pkg/registry"
    db_registry "github.com/yhonda-ohishi/db_service/src/registry"
)

func main() {
    // gRPCサーバー作成
    grpcServer := grpc.NewServer()

    // dtako_eventsサービス登録
    dtako_registry.Register(grpcServer)

    // db_serviceサービス自動登録（1行で完了）
    db_registry.Register(grpcServer)

    // サーバー起動
    listener, _ := net.Listen("tcp", ":50051")
    grpcServer.Serve(listener)
}
```

### db_service利用（内部処理）

```go
// internal/processor/etc_processor.go
type ETCProcessor struct {
    etcService pb.ETCMeisaiServiceClient
}

func NewETCProcessor(conn *grpc.ClientConn) *ETCProcessor {
    return &ETCProcessor{
        etcService: pb.NewETCMeisaiServiceClient(conn),
    }
}

// または同一プロセスで直接呼び出し
func (p *ETCProcessor) SetETCData(ctx context.Context, ids []string) error {
    resp, err := p.etcService.List(ctx, &pb.ListRequest{
        Filter: &pb.Filter{UnkoNo: strings.Join(ids, ",")},
    })
    // 処理
}
```

## 環境設定

### .env設定

```env
# データベース設定
DB_HOST=localhost
DB_PORT=3306
DB_USER=your_username
DB_PASSWORD=your_password
DB_NAME=dtako_db

# gRPC設定
GRPC_PORT=50052

# db_service連携（オプション）
DB_SERVICE_HOST=localhost
DB_SERVICE_PORT=50051

# 外部API
GOOGLE_MAPS_API_KEY=your_api_key

# CSVインポート設定
CSV_IMPORT_DIR=/var/dtako_csv/
CSV_TMP_DIR=/var/dtako_csv/tmp/
```

## API使用例

### イベント検索

```bash
# grpcurl使用例
grpcurl -plaintext -d '{
  "date_from": "2021-12-01",
  "date_to": "2021-12-31"
}' localhost:50052 dtako.DtakoEventService/SearchByDateRange
```

### CSVインポート

```bash
grpcurl -plaintext -d '{
  "skip": false,
  "test_mode": false
}' localhost:50052 dtako.CSVImportService/AutoLoad
```

### 位置情報更新

```bash
grpcurl -plaintext -d '{
  "latitude": 35.6812,
  "longitude": 139.7671
}' localhost:50052 dtako.LocationService/ReverseGeocode
```

## テスト戦略（etc_data_processorパターン）

### 目標
- 手書きコード100%カバレッジ
- 包括的なエラーハンドリング
- エッジケース網羅

### テスト構成
```
tests/
├── unit/                    # ユニットテスト
│   ├── models_test.go
│   ├── repository_test.go
│   └── service_test.go
├── integration/             # 統合テスト
│   ├── csv_import_test.go
│   ├── db_service_test.go
│   └── end_to_end_test.go
└── fixtures/                # テストデータ
    ├── KUDGURI_test.csv
    └── sample_events.json
```

### カバレッジ計測
```bash
make test-coverage
./show_coverage.sh
```

## デプロイメント

### ビルド

```bash
# プロトコルバッファコンパイル
make proto

# ビルド
make build

# テスト
make test

# 実行
./bin/dtako_events
```

### Docker対応

```dockerfile
FROM golang:1.21-alpine AS builder
WORKDIR /app
COPY . .
RUN go mod download
RUN go build -o dtako_events cmd/server/main.go

FROM alpine:latest
RUN apk --no-cache add ca-certificates
WORKDIR /root/
COPY --from=builder /app/dtako_events .
COPY .env .
EXPOSE 50052
CMD ["./dtako_events"]
```

## セキュリティ

- **環境変数必須**: DB認証情報は環境変数から取得
- **ハードコード禁止**: シークレット情報のハードコード禁止
- **.gitignore**: `.env`ファイルはバージョン管理対象外
- **gRPC認証**: TLS/mTLS対応（本番環境）

## マイグレーション計画

### フェーズ1: 基盤構築
1. プロジェクト構造作成
2. Protocol Buffers定義
3. データモデル実装（GORM）
4. 基本CRUD実装

### フェーズ2: コア機能移植
1. CSVインポート機能
2. イベント検索機能
3. 位置情報処理

### フェーズ3: 外部連携
1. db_service統合
2. 一番星連携
3. ETC/フェリーデータ処理

### フェーズ4: 最適化
1. パフォーマンスチューニング
2. キャッシュ機構
3. ストリーミング対応

## 参考資料

- [desktop-server](https://github.com/yhonda-ohishi-pub-dev/desktop-server) - クライアント統合、gRPC-Web
- [db_service](https://github.com/yhonda-ohishi/db_service) - リポジトリパターン、サービス自動登録
- [etc_data_processor](https://github.com/yhonda-ohishi/etc_data_processor) - CSVパース、100%カバレッジ

## ライセンス

MIT License

## 作成者

yhonda-ohishi
