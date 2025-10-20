module github.com/yhonda-ohishi/dtako_events

go 1.25.1

require (
	github.com/joho/godotenv v1.5.1
	google.golang.org/grpc v1.76.0
	google.golang.org/protobuf v1.36.10
	gorm.io/driver/mysql v1.5.2
	gorm.io/gorm v1.25.5
)

require (
	filippo.io/edwards25519 v1.1.0 // indirect
	github.com/cenkalti/backoff/v4 v4.1.1 // indirect
	github.com/denisenkom/go-mssqldb v0.12.3 // indirect
	github.com/desertbit/timer v0.0.0-20180107155436-c41aec40b27f // indirect
	github.com/getlantern/context v0.0.0-20190109183933-c447772a6520 // indirect
	github.com/getlantern/errors v0.0.0-20190325191628-abdb3e3e36f7 // indirect
	github.com/getlantern/golog v0.0.0-20190830074920-4ef2e798c2d7 // indirect
	github.com/getlantern/hex v0.0.0-20190417191902-c6586a6fe0b7 // indirect
	github.com/getlantern/hidden v0.0.0-20190325191715-f02dbb02be55 // indirect
	github.com/getlantern/ops v0.0.0-20190325191751-d70cb0d6f85f // indirect
	github.com/getlantern/systray v1.2.2 // indirect
	github.com/go-sql-driver/mysql v1.9.3 // indirect
	github.com/go-stack/stack v1.8.1 // indirect
	github.com/golang-sql/civil v0.0.0-20190719163853-cb61b32ac6fe // indirect
	github.com/golang-sql/sqlexp v0.1.0 // indirect
	github.com/grpc-ecosystem/grpc-gateway/v2 v2.27.3 // indirect
	github.com/improbable-eng/grpc-web v0.15.0 // indirect
	github.com/jinzhu/inflection v1.0.0 // indirect
	github.com/jinzhu/now v1.1.5 // indirect
	github.com/klauspost/compress v1.16.7 // indirect
	github.com/oxtoacart/bpool v0.0.0-20190530202638-03653db5a59c // indirect
	github.com/rs/cors v1.7.0 // indirect
	github.com/yhonda-ohishi-pub-dev/desktop-server v1.3.24 // indirect
	github.com/yhonda-ohishi/db_service v1.3.0 // indirect
	github.com/yhonda-ohishi/etc_data_processor v1.0.0 // indirect
	github.com/yhonda-ohishi/etc_meisai_scraper v0.0.22 // indirect
	golang.org/x/crypto v0.43.0 // indirect
	golang.org/x/net v0.46.0 // indirect
	golang.org/x/sys v0.37.0 // indirect
	golang.org/x/text v0.30.0 // indirect
	google.golang.org/genproto/googleapis/api v0.0.0-20251014184007-4626949a642f // indirect
	google.golang.org/genproto/googleapis/rpc v0.0.0-20251014184007-4626949a642f // indirect
	nhooyr.io/websocket v1.8.6 // indirect
)

// desktop-serverの内部importパスを置き換え
replace desktop-server => github.com/yhonda-ohishi-pub-dev/desktop-server v1.3.23
