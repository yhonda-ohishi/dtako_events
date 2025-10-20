package models

// IchibanUntennippoMeisai 一番星 運転日報明細
// 注意: 一番星DBは別接続（'ichi'）
type IchibanUntennippoMeisai struct {
	SharyouCC     string  `gorm:"column:車輌C+車輌H;type:computed"`
	TsumikomiYMD  string  `gorm:"column:積込年月日"`
	UnkoYMD       string  `gorm:"column:運行年月日"`
	NounyuuYMD    string  `gorm:"column:納入年月日"`
	HatsuchiN     string  `gorm:"column:発地N"`
	ChakuchiN     string  `gorm:"column:着地N"`
	HinmeiN       string  `gorm:"column:品名N"`
	TokuisakiN    string  `gorm:"column:得意先N"`
	TokuisakiCC   string  `gorm:"column:得意先CC;type:computed"`
	Biko          string  `gorm:"column:備考"`
	Biko2         string  `gorm:"column:備考2"`
	SeikyuK       int     `gorm:"column:請求K"`
	ShainN        string  `gorm:"column:社員N"`
	UntenshC      string  `gorm:"column:運転手C"`
	Kingaku       int     `gorm:"column:金額"`
	Nebiki        int     `gorm:"column:値引"`
	Warimashi     int     `gorm:"column:割増"`
	Jippi         int     `gorm:"column:実費"`
	NyuuryokuTantouN string `gorm:"column:入力担当N"`
}

func (IchibanUntennippoMeisai) TableName() string {
	return "運転日報明細"
}

// IchibanTokuisakiMasta 一番星 得意先マスタ
type IchibanTokuisakiMasta struct {
	TokuisakiCC string `gorm:"primaryKey;column:得意先C+得意先H;type:computed"`
	TokuisakiN  string `gorm:"column:得意先N"`
}

func (IchibanTokuisakiMasta) TableName() string {
	return "得意先ﾏｽﾀ"
}

// IchibanShainMasta 一番星 社員マスタ
type IchibanShainMasta struct {
	ShainC string `gorm:"primaryKey;column:社員C"`
	ShainN string `gorm:"column:社員N"`
}

func (IchibanShainMasta) TableName() string {
	return "社員ﾏｽﾀ"
}

// IchibanKeihiMeisai 一番星 経費明細
type IchibanKeihiMeisai struct {
	SharyouCC    string  `gorm:"column:車輌C+車輌H;type:computed"`
	UnkoYMD      string  `gorm:"column:運行年月日"`
	KeijouYMD    string  `gorm:"column:計上年月日"`
	Suuryou      float64 `gorm:"column:数量"`
	Tanka        float64 `gorm:"column:単価"`
	Kingaku      int     `gorm:"column:金額"`
	Biko         string  `gorm:"column:備考"`
	KeihiC       string  `gorm:"column:経費C"`
	KeihiN       string  `gorm:"column:経費N"`
	MiharaiSakiN string  `gorm:"column:未払先N"`
}

func (IchibanKeihiMeisai) TableName() string {
	return "経費明細"
}

// IchibanKeihiMasta 一番星 経費マスタ
type IchibanKeihiMasta struct {
	KeihiC string `gorm:"primaryKey;column:経費C"`
	KeihiN string `gorm:"column:経費N"`
}

func (IchibanKeihiMasta) TableName() string {
	return "経費ﾏｽﾀ"
}

// IchibanMiharaiSakiMasta 一番星 未払先マスタ
type IchibanMiharaiSakiMasta struct {
	MiharaiSakiC string `gorm:"primaryKey;column:未払先C"`
	MiharaiSakiH string `gorm:"column:未払先H"`
	MiharaiSakiN string `gorm:"column:未払先N"`
}

func (IchibanMiharaiSakiMasta) TableName() string {
	return "未払先ﾏｽﾀ"
}
