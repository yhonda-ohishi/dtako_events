package models

import (
	"time"
)

// RyohiRow 料費データ
type RyohiRow struct {
	ID          uint      `gorm:"primaryKey;autoIncrement"`
	UnkoNo      string    `gorm:"column:unko_no;index"`
	TsumiDate   string    `gorm:"column:tsumi_date"`
	OroshiDate  string    `gorm:"column:oroshi_date"`
	Tokuisaki   string    `gorm:"column:tokuisaki"`
	Status      string    `gorm:"column:status"`
	CreatedAt   time.Time `gorm:"column:created"`
	UpdatedAt   time.Time `gorm:"column:modified"`
}

// TableName テーブル名を指定
func (RyohiRow) TableName() string {
	return "ryohi_rows"
}

// RyohiRowPrecheck 料費事前チェックデータ
type RyohiRowPrecheck struct {
	ID          uint      `gorm:"primaryKey;autoIncrement"`
	DtakoRowID  string    `gorm:"column:dtako_row_id;index"`
	UnkoNo      string    `gorm:"column:unko_no;index"`
	Status      string    `gorm:"column:status"`
	CreatedAt   time.Time `gorm:"column:created"`
	UpdatedAt   time.Time `gorm:"column:modified"`
}

func (RyohiRowPrecheck) TableName() string {
	return "ryohi_row_prechecks"
}

// EtcMeisaiAftOroshi ETC明細（卸後）
type EtcMeisaiAftOroshi struct {
	ID          uint      `gorm:"primaryKey;autoIncrement"`
	DtakoRowID  string    `gorm:"column:dtako_row_id;index"`
	DateFr      time.Time `gorm:"column:date_fr"`
	DateTo      time.Time `gorm:"column:date_to"`
	Kingaku     int       `gorm:"column:kingaku"`
	CreatedAt   time.Time `gorm:"column:created"`
	UpdatedAt   time.Time `gorm:"column:modified"`
}

func (EtcMeisaiAftOroshi) TableName() string {
	return "etc_meisai_aft_oroshi"
}
