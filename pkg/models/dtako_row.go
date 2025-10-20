package models

import (
	"time"
)

// DtakoRow 運行データ
type DtakoRow struct {
	ID        string    `gorm:"primaryKey;column:id"`
	UnkoNo    string    `gorm:"column:運行NO;index"`
	SharyouCC string    `gorm:"column:車輌CC;index"`
	JomuinCD1 string    `gorm:"column:乗務員CD1;index"`
	ShukkoDateTime time.Time `gorm:"column:出庫日時;index"`
	KikoDateTime   *time.Time `gorm:"column:帰庫日時"`
	UnkoDate       time.Time  `gorm:"column:運行日;index"`

	// 対象乗務員区分
	TaishouJomuinKubun int `gorm:"column:対象乗務員区分"`

	// 走行距離・燃料
	SoukouKyori  float64 `gorm:"column:走行距離"`
	NenryouShiyou float64 `gorm:"column:燃料使用量"`

	CreatedAt time.Time `gorm:"column:created"`
	UpdatedAt time.Time `gorm:"column:modified"`

	// リレーション
	DtakoEvents     []DtakoEvent      `gorm:"foreignKey:UnkoNo;references:UnkoNo"`
	Driver          *Driver           `gorm:"foreignKey:JomuinCD1;references:Code"`
	DtakoFerryRows  []DtakoFerryRow   `gorm:"foreignKey:UnkoNo;references:UnkoNo"`
	RyohiRows       []RyohiRow        `gorm:"foreignKey:UnkoNo;references:UnkoNo"`
	EtcMeisai       []EtcMeisai       `gorm:"foreignKey:DtakoRowID;references:ID"`
	DtakoUriageKeihi []DtakoUriageKeihi `gorm:"foreignKey:DtakoRowID;references:ID"`
}

// TableName テーブル名を指定
func (DtakoRow) TableName() string {
	return "dtako_rows"
}

// Driver 運転手マスタ
type Driver struct {
	Code string `gorm:"primaryKey;column:社員C"`
	Name string `gorm:"column:社員N"`
}

func (Driver) TableName() string {
	return "社員ﾏｽﾀ"
}

// DtakoFerryRow フェリーデータ
type DtakoFerryRow struct {
	ID            uint      `gorm:"primaryKey;autoIncrement"`
	UnkoNo        string    `gorm:"column:運行NO;index"`
	StartDateTime time.Time `gorm:"column:開始日時"`
	EndDateTime   *time.Time `gorm:"column:終了日時"`
	FerryName     string    `gorm:"column:フェリー名"`
	Kingaku       int       `gorm:"column:金額"`
	CreatedAt     time.Time `gorm:"column:created"`
	UpdatedAt     time.Time `gorm:"column:modified"`
}

func (DtakoFerryRow) TableName() string {
	return "dtako_ferry_rows"
}

// EtcMeisai ETC明細データ
type EtcMeisai struct {
	ID          uint       `gorm:"primaryKey;autoIncrement"`
	DtakoRowID  string     `gorm:"column:dtako_row_id;index"`
	DateFr      time.Time  `gorm:"column:date_fr"`
	DateTo      time.Time  `gorm:"column:date_to"`
	Kingaku     int        `gorm:"column:kingaku"`
	CreatedAt   time.Time  `gorm:"column:created"`
	UpdatedAt   time.Time  `gorm:"column:modified"`

	// リレーション
	DtakoUriageKeihi *DtakoUriageKeihi `gorm:"foreignKey:SrchID;references:ID"`
}

func (EtcMeisai) TableName() string {
	return "etc_meisai"
}

// DtakoEtc 旧ETCデータ
type DtakoEtc struct {
	ID            uint       `gorm:"primaryKey;autoIncrement"`
	UnkoNo        string     `gorm:"column:unko_no;index"`
	StartDateTime time.Time  `gorm:"column:start_datetime"`
	EndDateTime   *time.Time `gorm:"column:end_datetime"`
	Etc           int        `gorm:"column:etc"`
	CreatedAt     time.Time  `gorm:"column:created"`
	UpdatedAt     time.Time  `gorm:"column:modified"`

	// リレーション
	DtakoUriageKeihi []DtakoUriageKeihi `gorm:"foreignKey:DtakoRowID;references:UnkoNo"`
}

func (DtakoEtc) TableName() string {
	return "dtako_etc"
}

// DtakoUriageKeihi 売上経費データ
type DtakoUriageKeihi struct {
	SrchID      string    `gorm:"primaryKey;column:srch_id"`
	DtakoRowID  string    `gorm:"column:dtako_row_id;index"`
	KeihiC      int       `gorm:"column:keihi_c"`
	Datetime    time.Time `gorm:"column:datetime"`
	Kingaku     int       `gorm:"column:kingaku"`
	CreatedAt   time.Time `gorm:"column:created"`
	UpdatedAt   time.Time `gorm:"column:modified"`

	// リレーション
	DtakoUriageKeihiChild []DtakoUriageKeihiChild `gorm:"foreignKey:ParentSrchID;references:SrchID"`
}

func (DtakoUriageKeihi) TableName() string {
	return "dtako_uriage_keihi"
}

// DtakoUriageKeihiChild 売上経費子データ
type DtakoUriageKeihiChild struct {
	ID            uint      `gorm:"primaryKey;autoIncrement"`
	ParentSrchID  string    `gorm:"column:parent_srch_id;index"`
	DtakoRowID    string    `gorm:"column:dtako_row_id;index"`
	CreatedAt     time.Time `gorm:"column:created"`
	UpdatedAt     time.Time `gorm:"column:modified"`
}

func (DtakoUriageKeihiChild) TableName() string {
	return "dtako_uriage_keihi_child"
}

// DtakoFuelTanka 燃料単価マスタ
type DtakoFuelTanka struct {
	MonthInt  int     `gorm:"primaryKey;column:month_int"`
	Tanka     float64 `gorm:"column:tanka"`
	CreatedAt time.Time `gorm:"column:created"`
	UpdatedAt time.Time `gorm:"column:modified"`
}

func (DtakoFuelTanka) TableName() string {
	return "dtako_fuel_tanka"
}
