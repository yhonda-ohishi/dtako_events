package service

import (
	"context"
	"fmt"
	"time"

	dbpb "github.com/yhonda-ohishi/db_service/src/proto"
	pb "github.com/yhonda-ohishi/dtako_events/proto"
	"google.golang.org/grpc"
	"google.golang.org/grpc/credentials/insecure"
	"google.golang.org/protobuf/types/known/timestamppb"
)

// DtakoEventService イベントデータサービス（db_service経由）
type DtakoEventService struct {
	pb.UnimplementedDtakoEventServiceServer
	dbEventsClient dbpb.DTakoEventsServiceClient
}

// NewDtakoEventService サービスを作成
// db_serviceに接続（同一プロセス内またはローカルホスト）
func NewDtakoEventService() *DtakoEventService {
	// TODO: 接続先を環境変数で設定可能にする
	conn, err := grpc.Dial("localhost:50051",
		grpc.WithTransportCredentials(insecure.NewCredentials()))
	if err != nil {
		// 本番では適切なエラーハンドリング
		panic(fmt.Sprintf("failed to connect to db_service: %v", err))
	}

	return &DtakoEventService{
		dbEventsClient: dbpb.NewDTakoEventsServiceClient(conn),
	}
}

// GetEvent イベントを取得
func (s *DtakoEventService) GetEvent(ctx context.Context, req *pb.GetEventRequest) (*pb.Event, error) {
	// db_serviceから取得
	resp, err := s.dbEventsClient.Get(ctx, &dbpb.GetDTakoEventsRequest{
		Id: parseEventID(req.SrchId),
	})
	if err != nil {
		return nil, fmt.Errorf("failed to get event from db_service: %w", err)
	}

	return convertDBEventToProto(resp.DtakoEvents), nil
}

// GetByUnkoNo 運行NO指定でイベント一覧取得
func (s *DtakoEventService) GetByUnkoNo(ctx context.Context, req *pb.GetByUnkoNoRequest) (*pb.GetByUnkoNoResponse, error) {
	// db_serviceから取得
	resp, err := s.dbEventsClient.GetByOperationNo(ctx, &dbpb.GetDTakoEventsByOperationNoRequest{
		OperationNo: req.UnkoNo,
	})
	if err != nil {
		return nil, fmt.Errorf("failed to get events from db_service: %w", err)
	}

	// 時刻フィルタリング（クライアント側）
	events := filterEventsByTime(resp.Items, req.StartTime, req.EndTime)

	pbEvents := make([]*pb.Event, len(events))
	for i, event := range events {
		pbEvents[i] = convertDBEventToProto(event)
	}

	return &pb.GetByUnkoNoResponse{
		Events: pbEvents,
	}, nil
}

// AggregateByEventType イベント種別ごとの集計
func (s *DtakoEventService) AggregateByEventType(ctx context.Context, req *pb.AggregateByEventTypeRequest) (*pb.AggregateByEventTypeResponse, error) {
	// db_serviceから全イベント取得
	var allEvents []*dbpb.DTakoEvents

	if req.UnkoNo != "" {
		// 運行NO指定
		resp, err := s.dbEventsClient.GetByOperationNo(ctx, &dbpb.GetDTakoEventsByOperationNoRequest{
			OperationNo: req.UnkoNo,
		})
		if err != nil {
			return nil, fmt.Errorf("failed to get events from db_service: %w", err)
		}
		allEvents = resp.Items
	} else {
		// 全イベント取得（TODO: ページネーション対応）
		resp, err := s.dbEventsClient.List(ctx, &dbpb.ListDTakoEventsRequest{
			Limit:  1000,
			Offset: 0,
		})
		if err != nil {
			return nil, fmt.Errorf("failed to list events from db_service: %w", err)
		}
		allEvents = resp.Items
	}

	// 時刻フィルタリング
	events := filterEventsByTime(allEvents, req.StartTime, req.EndTime)

	// イベント種別ごとに集計
	stats := make(map[string]*eventTypeStats)

	for _, event := range events {
		if _, exists := stats[event.EventName]; !exists {
			stats[event.EventName] = &eventTypeStats{
				EventType: event.EventName,
			}
		}

		stat := stats[event.EventName]
		stat.Count++

		// 時間計算（section_time: 秒 → 分）
		durationMinutes := float64(event.SectionTime) / 60.0
		stat.TotalDurationMinutes += durationMinutes

		// 距離集計（db_serviceから取得）
		stat.TotalSectionDistance += event.SectionDistance

		// 走行距離差分（終了メーター - 開始メーター）
		mileageDiff := event.EndMileage - event.StartMileage
		stat.TotalMileageDiff += mileageDiff
	}

	// 平均を計算
	aggregates := make([]*pb.EventTypeAggregate, 0, len(stats))
	total := &pb.EventTypeAggregate{
		EventType: "合計",
	}

	for _, stat := range stats {
		if stat.Count > 0 {
			stat.AvgDurationMinutes = stat.TotalDurationMinutes / float64(stat.Count)
			stat.AvgSectionDistance = stat.TotalSectionDistance / float64(stat.Count)
			stat.AvgMileageDiff = stat.TotalMileageDiff / float64(stat.Count)
		}

		aggregates = append(aggregates, &pb.EventTypeAggregate{
			EventType:            stat.EventType,
			Count:                int32(stat.Count),
			TotalDurationMinutes: stat.TotalDurationMinutes,
			AvgDurationMinutes:   stat.AvgDurationMinutes,
			TotalSectionDistance: stat.TotalSectionDistance,
			AvgSectionDistance:   stat.AvgSectionDistance,
			TotalMileageDiff:     stat.TotalMileageDiff,
			AvgMileageDiff:       stat.AvgMileageDiff,
		})

		// 合計に加算
		total.Count += int32(stat.Count)
		total.TotalDurationMinutes += stat.TotalDurationMinutes
		total.TotalSectionDistance += stat.TotalSectionDistance
		total.TotalMileageDiff += stat.TotalMileageDiff
	}

	// 合計の平均を計算
	if total.Count > 0 {
		total.AvgDurationMinutes = total.TotalDurationMinutes / float64(total.Count)
		total.AvgSectionDistance = total.TotalSectionDistance / float64(total.Count)
		total.AvgMileageDiff = total.TotalMileageDiff / float64(total.Count)
	}

	return &pb.AggregateByEventTypeResponse{
		Aggregates: aggregates,
		Total:      total,
	}, nil
}

// ヘルパー関数

type eventTypeStats struct {
	EventType              string
	Count                  int
	TotalDurationMinutes   float64
	AvgDurationMinutes     float64
	TotalSectionDistance   float64
	AvgSectionDistance     float64
	TotalMileageDiff       float64
	AvgMileageDiff         float64
}

func convertDBEventToProto(event *dbpb.DTakoEvents) *pb.Event {
	if event == nil {
		return nil
	}

	pbEvent := &pb.Event{
		SrchId:         fmt.Sprintf("%d", event.Id),
		EventType:      event.EventName,
		UnkoNo:         event.OperationNo,
		DriverId:       fmt.Sprintf("%d", event.DriverCode1),
		StartLatitude:  convertGPSToFloat(event.StartGpsLatitude),
		StartLongitude: convertGPSToFloat(event.StartGpsLongitude),
		StartCityName:  event.StartCityName,
		EndLatitude:    convertGPSToFloat(event.EndGpsLatitude),
		EndLongitude:   convertGPSToFloat(event.EndGpsLongitude),
	}

	// 日時変換
	if startTime, err := time.Parse(time.RFC3339, event.StartDatetime); err == nil {
		pbEvent.StartDatetime = timestamppb.New(startTime)
		pbEvent.CreatedAt = timestamppb.New(startTime)
	}

	if endTime, err := time.Parse(time.RFC3339, event.EndDatetime); err == nil {
		pbEvent.EndDatetime = timestamppb.New(endTime)
		pbEvent.UpdatedAt = timestamppb.New(endTime)
	}

	pbEvent.EndCityName = event.EndCityName

	return pbEvent
}

func filterEventsByTime(events []*dbpb.DTakoEvents, startTime, endTime *timestamppb.Timestamp) []*dbpb.DTakoEvents {
	if startTime == nil && endTime == nil {
		return events
	}

	filtered := make([]*dbpb.DTakoEvents, 0, len(events))
	for _, event := range events {
		eventTime, err := time.Parse(time.RFC3339, event.StartDatetime)
		if err != nil {
			continue
		}

		if startTime != nil && eventTime.Before(startTime.AsTime()) {
			continue
		}

		if endTime != nil && eventTime.After(endTime.AsTime()) {
			continue
		}

		filtered = append(filtered, event)
	}

	return filtered
}

func parseEventID(srchID string) int64 {
	var id int64
	fmt.Sscanf(srchID, "%d", &id)
	return id
}

func convertGPSToFloat(gpsValue *int64) float64 {
	if gpsValue == nil {
		return 0
	}
	// GPS座標はスケーリングされている可能性があるので変換
	return float64(*gpsValue) / 1000000.0
}
