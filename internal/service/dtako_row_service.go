package service

import (
	"context"
	"fmt"
	"time"

	"github.com/yhonda-ohishi/dtako_events/internal/models"
	"github.com/yhonda-ohishi/dtako_events/internal/repository"
	pb "github.com/yhonda-ohishi/dtako_events/proto"
	"google.golang.org/protobuf/types/known/timestamppb"
)

// DtakoRowService 運行データサービス
type DtakoRowService struct {
	pb.UnimplementedDtakoRowServiceServer
	rowRepo      repository.DtakoRowRepository
	eventRepo    repository.DtakoEventRepository
	ichibanRepo  repository.IchibanRepository
	fuelTankaRepo repository.FuelTankaRepository
}

// NewDtakoRowService サービスを作成
func NewDtakoRowService(
	rowRepo repository.DtakoRowRepository,
	eventRepo repository.DtakoEventRepository,
	ichibanRepo repository.IchibanRepository,
	fuelTankaRepo repository.FuelTankaRepository,
) *DtakoRowService {
	return &DtakoRowService{
		rowRepo:       rowRepo,
		eventRepo:     eventRepo,
		ichibanRepo:   ichibanRepo,
		fuelTankaRepo: fuelTankaRepo,
	}
}

// GetRowDetail 運行データの詳細を取得（view機能）
func (s *DtakoRowService) GetRowDetail(ctx context.Context, req *pb.GetRowDetailRequest) (*pb.GetRowDetailResponse, error) {
	// 運行データ本体を取得（関連データ含む）
	dtakoRow, err := s.rowRepo.GetDetailByID(ctx, req.Id)
	if err != nil {
		return nil, fmt.Errorf("failed to get dtako row: %w", err)
	}

	// レスポンスを構築
	resp := &pb.GetRowDetailResponse{
		DtakoRow: convertDtakoRowToProto(dtakoRow),
		Events:   make([]*pb.Event, 0),
	}

	// イベントデータ
	for _, event := range dtakoRow.DtakoEvents {
		resp.Events = append(resp.Events, convertDtakoEventToProto(&event))
	}

	// 積み・降しペアを作成
	resp.TsumiOroshiPairs = s.buildTsumiOroshiPairs(dtakoRow.DtakoEvents)

	// 前後の運行データ取得
	dtakoLast, err := s.rowRepo.GetPreviousRow(ctx, dtakoRow.JomuinCD1, dtakoRow.SharyouCC, dtakoRow.ShukkoDateTime)
	if err == nil && dtakoLast != nil {
		resp.DtakoLast = convertDtakoRowToProto(dtakoLast)
		resp.IsLastOroshiLast = s.isLastOroshiLast(dtakoLast)
	}

	dtakoNext, err := s.rowRepo.GetNextRow(ctx, dtakoRow.JomuinCD1, dtakoRow.SharyouCC, dtakoRow.ShukkoDateTime, 1)
	if err == nil && dtakoNext != nil {
		resp.DtakoNext = convertDtakoRowToProto(dtakoNext)
		resp.IsNextOroshiFst = s.isNextOroshiFst(dtakoNext)
	}

	// 積み降しイベント一覧
	resp.TsumiOroshi, err = s.getTsumiOroshiEvents(ctx, req.Id)
	if err != nil {
		return nil, fmt.Errorf("failed to get tsumi oroshi events: %w", err)
	}

	// フェリーデータ
	resp.Dferry = s.convertFerryData(dtakoRow.DtakoFerryRows)

	// ETCデータ（新EtcMeisai）
	resp.Ddetc = s.convertEtcMeisaiData(dtakoRow.EtcMeisai, dtakoRow.DtakoUriageKeihi, req.Id)
	resp.DdetcSrchCount = s.countEtcMeisaiWithoutUriage(dtakoRow.EtcMeisai)

	// 売上経費データ
	resp.DUriage = s.convertUriageKeihiData(dtakoRow.DtakoUriageKeihi)

	// 料費データ
	resp.RyohiRows = s.convertRyohiRows(dtakoRow.RyohiRows)

	// 一番星データ
	if dtakoRow.KikoDateTime != nil && s.ichibanRepo != nil {
		ichibanData, keihiData, err := s.getIchibanData(ctx, dtakoRow)
		if err == nil {
			resp.IchiR = ichibanData
			resp.Keihi = keihiData
		}
	}

	// 燃料単価
	if dtakoRow.KikoDateTime != nil {
		monthInt := dtakoRow.KikoDateTime.Year()*100 + int(dtakoRow.KikoDateTime.Month())
		fuelTanka, err := s.fuelTankaRepo.GetLatestByMonthInt(ctx, monthInt)
		if err == nil && fuelTanka != nil {
			resp.FuelTanka = &pb.FuelTanka{
				MonthInt: int32(fuelTanka.MonthInt),
				Tanka:    fuelTanka.Tanka,
			}
		}
	}

	return resp, nil
}

// buildTsumiOroshiPairs 積み・降しペアを作成
func (s *DtakoRowService) buildTsumiOroshiPairs(events []models.DtakoEvent) []*pb.TsumiOroshiPair {
	pairs := make([]*pb.TsumiOroshiPair, 0)
	var currentPair *pb.TsumiOroshiPair

	for i := range events {
		event := &events[i]

		// 保留は除外
		if event.DtakoEventsDetail != nil && event.DtakoEventsDetail.Biko == "保留" {
			continue
		}

		if event.EventName == "積み" && event.Tokuisaki == nil {
			// 新しい積みペア開始
			currentPair = &pb.TsumiOroshiPair{
				Tsumi:  convertDtakoEventToProto(event),
				Oroshi: nil,
			}
			pairs = append(pairs, currentPair)
		} else if event.EventName == "降し" && len(pairs) > 0 {
			lastPair := pairs[len(pairs)-1]
			if lastPair.Oroshi == nil {
				// 既存ペアに降しを追加
				lastPair.Oroshi = convertDtakoEventToProto(event)
			} else if event.Tokuisaki == nil {
				// 降しのみの新規ペア
				pairs = append(pairs, &pb.TsumiOroshiPair{
					Tsumi:  nil,
					Oroshi: convertDtakoEventToProto(event),
				})
			}
		}
	}

	return pairs
}

// isNextOroshiFst 次の運行が降し最初かチェック
func (s *DtakoRowService) isNextOroshiFst(dtakoNext *models.DtakoRow) *pb.Event {
	if dtakoNext == nil {
		return nil
	}

	for i := range dtakoNext.DtakoEvents {
		event := &dtakoNext.DtakoEvents[i]
		if event.Tokuisaki != nil && *event.Tokuisaki == "除外" {
			continue
		}

		if event.EventName == "降し" {
			return convertDtakoEventToProto(event)
		}
		if event.EventName == "積み" {
			return nil
		}
	}
	return nil
}

// isLastOroshiLast 前の運行が積み最後かチェック
func (s *DtakoRowService) isLastOroshiLast(dtakoLast *models.DtakoRow) *pb.Event {
	if dtakoLast == nil {
		return nil
	}

	var lastTsumi *models.DtakoEvent
	for i := range dtakoLast.DtakoEvents {
		event := &dtakoLast.DtakoEvents[i]
		if event.Tokuisaki != nil && (*event.Tokuisaki == "除外" || *event.Tokuisaki == "error") {
			continue
		}

		if event.EventName == "降し" {
			lastTsumi = nil
		} else if event.EventName == "積み" {
			lastTsumi = event
		}
	}

	if lastTsumi != nil {
		return convertDtakoEventToProto(lastTsumi)
	}
	return nil
}

// getTsumiOroshiEvents 積み降しイベント一覧取得
func (s *DtakoRowService) getTsumiOroshiEvents(ctx context.Context, unkoNo string) ([]*pb.Event, error) {
	events, err := s.eventRepo.GetTsumiOroshiByUnkoNo(ctx, unkoNo)
	if err != nil {
		return nil, err
	}

	protoEvents := make([]*pb.Event, len(events))
	for i, event := range events {
		protoEvents[i] = convertDtakoEventToProto(event)
	}
	return protoEvents, nil
}

// convertFerryData フェリーデータ変換
func (s *DtakoRowService) convertFerryData(ferries []models.DtakoFerryRow) []*pb.FerryData {
	result := make([]*pb.FerryData, len(ferries))
	for i, ferry := range ferries {
		result[i] = &pb.FerryData{
			Id:            fmt.Sprintf("%d", ferry.ID),
			UnkoNo:        ferry.UnkoNo,
			StartDatetime: timestamppb.New(ferry.StartDateTime),
			FerryName:     ferry.FerryName,
			Kingaku:       int32(ferry.Kingaku),
		}
		if ferry.EndDateTime != nil {
			result[i].EndDatetime = timestamppb.New(*ferry.EndDateTime)
		}
	}
	return result
}

// convertEtcMeisaiData ETC明細データ変換
func (s *DtakoRowService) convertEtcMeisaiData(etcMeisaiList []models.EtcMeisai, uriageList []models.DtakoUriageKeihi, dtakoRowID string) []*pb.EtcMeisaiData {
	result := make([]*pb.EtcMeisaiData, len(etcMeisaiList))

	// 売上リストIDを抽出
	uriageIDs := make([]string, 0)
	for _, uriage := range uriageList {
		if uriage.KeihiC == 0 {
			uriageIDs = append(uriageIDs, uriage.SrchID)
		}
	}

	for i, etc := range etcMeisaiList {
		result[i] = &pb.EtcMeisaiData{
			Id:          fmt.Sprintf("%d", etc.ID),
			DtakoRowId:  etc.DtakoRowID,
			DateFr:      timestamppb.New(etc.DateFr),
			DateTo:      timestamppb.New(etc.DateTo),
			Kingaku:     int32(etc.Kingaku),
			Tsumi:       make(map[string]string),
		}

		// 売上経費データがあれば設定
		if etc.DtakoUriageKeihi != nil {
			result[i].DtakoUriageKeihi = &pb.UriageKeihiData{
				SrchId:     etc.DtakoUriageKeihi.SrchID,
				DtakoRowId: etc.DtakoUriageKeihi.DtakoRowID,
				KeihiC:     int32(etc.DtakoUriageKeihi.KeihiC),
				Datetime:   timestamppb.New(etc.DtakoUriageKeihi.Datetime),
				Kingaku:    int32(etc.DtakoUriageKeihi.Kingaku),
			}
		}

		// 積みデータ設定は別途実装が必要（複雑なロジックのため）
		// TODO: PHP側のtsumiマッピングロジックを実装
	}

	return result
}

// countEtcMeisaiWithoutUriage 売上経費が未設定のETC明細数
func (s *DtakoRowService) countEtcMeisaiWithoutUriage(etcMeisaiList []models.EtcMeisai) int32 {
	count := 0
	for _, etc := range etcMeisaiList {
		if etc.DtakoUriageKeihi == nil {
			count++
		}
	}
	return int32(count)
}

// convertUriageKeihiData 売上経費データ変換
func (s *DtakoRowService) convertUriageKeihiData(uriageList []models.DtakoUriageKeihi) []*pb.UriageKeihiData {
	result := make([]*pb.UriageKeihiData, len(uriageList))
	for i, uriage := range uriageList {
		result[i] = &pb.UriageKeihiData{
			SrchId:     uriage.SrchID,
			DtakoRowId: uriage.DtakoRowID,
			KeihiC:     int32(uriage.KeihiC),
			Datetime:   timestamppb.New(uriage.Datetime),
			Kingaku:    int32(uriage.Kingaku),
			Children:   make([]*pb.UriageKeihiChild, len(uriage.DtakoUriageKeihiChild)),
		}

		for j, child := range uriage.DtakoUriageKeihiChild {
			result[i].Children[j] = &pb.UriageKeihiChild{
				Id:            fmt.Sprintf("%d", child.ID),
				ParentSrchId:  child.ParentSrchID,
				DtakoRowId:    child.DtakoRowID,
			}
		}
	}
	return result
}

// convertRyohiRows 料費データ変換
func (s *DtakoRowService) convertRyohiRows(ryohiList []models.RyohiRow) []*pb.RyohiRow {
	result := make([]*pb.RyohiRow, len(ryohiList))
	for i, ryohi := range ryohiList {
		result[i] = &pb.RyohiRow{
			Id:         uint32(ryohi.ID),
			UnkoNo:     ryohi.UnkoNo,
			TsumiDate:  ryohi.TsumiDate,
			OroshiDate: ryohi.OroshiDate,
			Tokuisaki:  ryohi.Tokuisaki,
			Status:     ryohi.Status,
			CreatedAt:  timestamppb.New(ryohi.CreatedAt),
			UpdatedAt:  timestamppb.New(ryohi.UpdatedAt),
		}
	}
	return result
}

// getIchibanData 一番星データ取得
func (s *DtakoRowService) getIchibanData(ctx context.Context, dtakoRow *models.DtakoRow) ([]*pb.IchibanData, []*pb.KeihiData, error) {
	// 運転日報明細取得
	untennippoMeisai, err := s.ichibanRepo.GetUntennippoMeisai(
		ctx,
		dtakoRow.ShukkoDateTime,
		*dtakoRow.KikoDateTime,
		dtakoRow.JomuinCD1,
		dtakoRow.SharyouCC,
	)
	if err != nil {
		return nil, nil, err
	}

	ichibanData := make([]*pb.IchibanData, len(untennippoMeisai))
	for i, meisai := range untennippoMeisai {
		ichibanData[i] = &pb.IchibanData{
			SharyouCc:         meisai.SharyouCC,
			TsumikomiYmd:      meisai.TsumikomiYMD,
			UnkoYmd:           meisai.UnkoYMD,
			NounyuuYmd:        meisai.NounyuuYMD,
			HatsuchiN:         meisai.HatsuchiN,
			ChakuchiN:         meisai.ChakuchiN,
			HinmeiN:           meisai.HinmeiN,
			TokuisakiN:        meisai.TokuisakiN,
			TokuisakiCc:       meisai.TokuisakiCC,
			Biko:              meisai.Biko,
			Biko2:             meisai.Biko2,
			SeikyuK:           int32(meisai.SeikyuK),
			ShainN:            meisai.ShainN,
			UntenshC:          meisai.UntenshC,
			Kingaku:           int32(meisai.Kingaku),
			Nebiki:            int32(meisai.Nebiki),
			Warimashi:         int32(meisai.Warimashi),
			Jippi:             int32(meisai.Jippi),
			NyuuryokuTantouN:  meisai.NyuuryokuTantouN,
		}
	}

	// 経費明細取得
	keihiMeisai, err := s.ichibanRepo.GetKeihiMeisai(
		ctx,
		dtakoRow.ShukkoDateTime,
		*dtakoRow.KikoDateTime,
		dtakoRow.JomuinCD1,
		dtakoRow.SharyouCC,
	)
	if err != nil {
		return ichibanData, nil, err
	}

	keihiData := make([]*pb.KeihiData, len(keihiMeisai))
	for i, meisai := range keihiMeisai {
		keihiData[i] = &pb.KeihiData{
			SharyouCc:    meisai.SharyouCC,
			UnkoYmd:      meisai.UnkoYMD,
			KeijouYmd:    meisai.KeijouYMD,
			Suuryou:      meisai.Suuryou,
			Tanka:        meisai.Tanka,
			Kingaku:      int32(meisai.Kingaku),
			Biko:         meisai.Biko,
			KeihiN:       meisai.KeihiN,
			KeihiC:       meisai.KeihiC,
			MiharaiSakiN: meisai.MiharaiSakiN,
		}
	}

	return ichibanData, keihiData, nil
}

// ヘルパー関数: モデルからProtoへの変換
func convertDtakoRowToProto(row *models.DtakoRow) *pb.Row {
	if row == nil {
		return nil
	}

	pbRow := &pb.Row{
		Id:         row.ID,
		UnkoNo:     row.UnkoNo,
		Shaban:     row.SharyouCC,
		DriverId:   row.JomuinCD1,
		StartDatetime: timestamppb.New(row.ShukkoDateTime),
		Distance:   row.SoukouKyori,
		FuelUsed:   row.NenryouShiyou,
		SharyouCc:  row.SharyouCC,
		JomuinCd1:  row.JomuinCD1,
		TaishouJomuinKubun: int32(row.TaishouJomuinKubun),
		CreatedAt:  timestamppb.New(row.CreatedAt),
		UpdatedAt:  timestamppb.New(row.UpdatedAt),
	}

	if row.KikoDateTime != nil {
		pbRow.EndDatetime = timestamppb.New(*row.KikoDateTime)
	}

	if row.Driver != nil {
		pbRow.Driver = &pb.Driver{
			Code: row.Driver.Code,
			Name: row.Driver.Name,
		}
	}

	return pbRow
}

func convertDtakoEventToProto(event *models.DtakoEvent) *pb.Event {
	if event == nil {
		return nil
	}

	pbEvent := &pb.Event{
		SrchId:        event.SrchID,
		EventType:     event.EventName,
		UnkoNo:        event.UnkoNo,
		DriverId:      event.JomuinCD1,
		StartDatetime: timestamppb.New(event.StartDateTime),
		StartLatitude: event.StartGPSLat,
		StartLongitude: event.StartGPSLon,
		StartCityName: event.StartCityName,
		CreatedAt:     timestamppb.New(event.CreatedAt),
		UpdatedAt:     timestamppb.New(event.UpdatedAt),
	}

	if event.EndDateTime != nil {
		pbEvent.EndDatetime = timestamppb.New(*event.EndDateTime)
	}
	if event.EndGPSLat != nil {
		pbEvent.EndLatitude = *event.EndGPSLat
	}
	if event.EndGPSLon != nil {
		pbEvent.EndLongitude = *event.EndGPSLon
	}
	if event.EndCityName != nil {
		pbEvent.EndCityName = *event.EndCityName
	}
	if event.Tokuisaki != nil {
		pbEvent.Tokuisaki = *event.Tokuisaki
	}

	if event.DtakoEventsDetail != nil {
		pbEvent.Biko = event.DtakoEventsDetail.Biko
	}

	return pbEvent
}

// 基本CRUD実装（省略版）
func (s *DtakoRowService) CreateRow(ctx context.Context, req *pb.CreateRowRequest) (*pb.Row, error) {
	// TODO: 実装
	return nil, fmt.Errorf("not implemented")
}

func (s *DtakoRowService) GetRow(ctx context.Context, req *pb.GetRowRequest) (*pb.Row, error) {
	row, err := s.rowRepo.GetByID(ctx, req.Id)
	if err != nil {
		return nil, err
	}
	return convertDtakoRowToProto(row), nil
}

func (s *DtakoRowService) UpdateRow(ctx context.Context, req *pb.UpdateRowRequest) (*pb.Row, error) {
	// TODO: 実装
	return nil, fmt.Errorf("not implemented")
}

func (s *DtakoRowService) DeleteRow(ctx context.Context, req *pb.DeleteRowRequest) (*pb.DeleteRowResponse, error) {
	err := s.rowRepo.Delete(ctx, req.Id)
	return &pb.DeleteRowResponse{Success: err == nil}, err
}

func (s *DtakoRowService) ListRows(ctx context.Context, req *pb.ListRowsRequest) (*pb.ListRowsResponse, error) {
	rows, total, err := s.rowRepo.List(ctx, int(req.Page), int(req.PageSize))
	if err != nil {
		return nil, err
	}

	pbRows := make([]*pb.Row, len(rows))
	for i, row := range rows {
		pbRows[i] = convertDtakoRowToProto(row)
	}

	return &pb.ListRowsResponse{
		Rows:  pbRows,
		Total: int32(total),
	}, nil
}

func (s *DtakoRowService) SearchRows(ctx context.Context, req *pb.SearchRowsRequest) (*pb.ListRowsResponse, error) {
	var dateFrom, dateTo *time.Time
	if req.DateFrom != nil {
		t := req.DateFrom.AsTime()
		dateFrom = &t
	}
	if req.DateTo != nil {
		t := req.DateTo.AsTime()
		dateTo = &t
	}

	rows, err := s.rowRepo.Search(ctx, dateFrom, dateTo, req.DriverId, req.Shaban)
	if err != nil {
		return nil, err
	}

	pbRows := make([]*pb.Row, len(rows))
	for i, row := range rows {
		pbRows[i] = convertDtakoRowToProto(row)
	}

	return &pb.ListRowsResponse{
		Rows:  pbRows,
		Total: int32(len(rows)),
	}, nil
}

func (s *DtakoRowService) SearchByShaban(ctx context.Context, req *pb.ShabanSearchRequest) (*pb.ListRowsResponse, error) {
	var dateFrom, dateTo *time.Time
	if req.DateFrom != nil {
		t := req.DateFrom.AsTime()
		dateFrom = &t
	}
	if req.DateTo != nil {
		t := req.DateTo.AsTime()
		dateTo = &t
	}

	rows, err := s.rowRepo.Search(ctx, dateFrom, dateTo, "", req.Shaban)
	if err != nil {
		return nil, err
	}

	pbRows := make([]*pb.Row, len(rows))
	for i, row := range rows {
		pbRows[i] = convertDtakoRowToProto(row)
	}

	return &pb.ListRowsResponse{
		Rows:  pbRows,
		Total: int32(len(rows)),
	}, nil
}
