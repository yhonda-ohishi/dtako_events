package models

import (
	"time"

	"gorm.io/gorm"
)

// DtakoEvent イベントデータ
type DtakoEvent struct {
	SrchID           string    `gorm:"primaryKey;column:srch_id"`
	EventName        string    `gorm:"column:イベント名;index"`
	UnkoNo           string    `gorm:"column:運行NO;index"`
	JomuinCD1        string    `gorm:"column:乗務員CD1;index"`
	SharyouCC        string    `gorm:"column:車輌CC;index"`

	StartDateTime    time.Time  `gorm:"column:開始日時;index"`
	EndDateTime      *time.Time `gorm:"column:終了日時"`

	StartGPSLat      float64 `gorm:"column:開始GPS緯度"`
	StartGPSLon      float64 `gorm:"column:開始GPS経度"`
	StartCityName    string  `gorm:"column:開始市町村名"`

	EndGPSLat        *float64 `gorm:"column:終了GPS緯度"`
	EndGPSLon        *float64 `gorm:"column:終了GPS経度"`
	EndCityName      *string  `gorm:"column:終了市町村名"`

	Tokuisaki        *string `gorm:"column:得意先"`

	CreatedAt        time.Time      `gorm:"column:created"`
	UpdatedAt        time.Time      `gorm:"column:modified"`
	DeletedAt        gorm.DeletedAt `gorm:"column:deleted_at;index"`

	// リレーション
	DtakoEventsDetail *DtakoEventsDetail `gorm:"foreignKey:SrchID;references:SrchID"`
	Driver            *Driver            `gorm:"foreignKey:JomuinCD1;references:Code"`
}

// TableName テーブル名を指定
func (DtakoEvent) TableName() string {
	return "dtako_events"
}

// DtakoEventsDetail イベント詳細
type DtakoEventsDetail struct {
	SrchID    string    `gorm:"primaryKey;column:srch_id"`
	Biko      string    `gorm:"column:備考"`
	CreatedAt time.Time `gorm:"column:created"`
	UpdatedAt time.Time `gorm:"column:modified"`
}

func (DtakoEventsDetail) TableName() string {
	return "dtako_events_details"
}
