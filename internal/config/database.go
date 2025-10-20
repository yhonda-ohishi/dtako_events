package config

import (
	"fmt"
	"os"
	"time"

	"gorm.io/driver/mysql"
	"gorm.io/gorm"
	"gorm.io/gorm/logger"
)

// DatabaseConfig データベース設定
type DatabaseConfig struct {
	Host     string
	Port     string
	User     string
	Password string
	DBName   string
}

// IchibanDatabaseConfig 一番星データベース設定
type IchibanDatabaseConfig struct {
	Host     string
	Port     string
	User     string
	Password string
	DBName   string
}

// LoadDatabaseConfig 環境変数からDB設定を読み込み
func LoadDatabaseConfig() *DatabaseConfig {
	return &DatabaseConfig{
		Host:     getEnv("DB_HOST", "localhost"),
		Port:     getEnv("DB_PORT", "3306"),
		User:     getEnv("DB_USER", "root"),
		Password: getEnv("DB_PASSWORD", ""),
		DBName:   getEnv("DB_NAME", "dtako_db"),
	}
}

// LoadIchibanDatabaseConfig 一番星DB設定を読み込み
func LoadIchibanDatabaseConfig() *IchibanDatabaseConfig {
	return &IchibanDatabaseConfig{
		Host:     getEnv("ICHIBAN_DB_HOST", "localhost"),
		Port:     getEnv("ICHIBAN_DB_PORT", "3306"),
		User:     getEnv("ICHIBAN_DB_USER", "root"),
		Password: getEnv("ICHIBAN_DB_PASSWORD", ""),
		DBName:   getEnv("ICHIBAN_DB_NAME", "ichiban_db"),
	}
}

// ConnectDatabase データベースに接続
func ConnectDatabase(config *DatabaseConfig) (*gorm.DB, error) {
	dsn := fmt.Sprintf("%s:%s@tcp(%s:%s)/%s?charset=utf8mb4&parseTime=True&loc=Local",
		config.User,
		config.Password,
		config.Host,
		config.Port,
		config.DBName,
	)

	db, err := gorm.Open(mysql.Open(dsn), &gorm.Config{
		Logger: logger.Default.LogMode(logger.Info),
		NowFunc: func() time.Time {
			return time.Now().Local()
		},
	})

	if err != nil {
		return nil, fmt.Errorf("failed to connect to database: %w", err)
	}

	sqlDB, err := db.DB()
	if err != nil {
		return nil, err
	}

	// コネクションプール設定
	sqlDB.SetMaxIdleConns(10)
	sqlDB.SetMaxOpenConns(100)
	sqlDB.SetConnMaxLifetime(time.Hour)

	return db, nil
}

// ConnectIchibanDatabase 一番星データベースに接続
func ConnectIchibanDatabase(config *IchibanDatabaseConfig) (*gorm.DB, error) {
	dsn := fmt.Sprintf("%s:%s@tcp(%s:%s)/%s?charset=utf8mb4&parseTime=True&loc=Local",
		config.User,
		config.Password,
		config.Host,
		config.Port,
		config.DBName,
	)

	db, err := gorm.Open(mysql.Open(dsn), &gorm.Config{
		Logger: logger.Default.LogMode(logger.Info),
	})

	if err != nil {
		return nil, fmt.Errorf("failed to connect to ichiban database: %w", err)
	}

	return db, nil
}

// getEnv 環境変数取得（デフォルト値付き）
func getEnv(key, defaultValue string) string {
	value := os.Getenv(key)
	if value == "" {
		return defaultValue
	}
	return value
}
