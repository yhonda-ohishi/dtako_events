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

// DtakoEventService イベントデータサービス
type DtakoEventService struct {
	pb.UnimplementedDtakoEventServiceServer
	eventRepo repository.DtakoEventRepository
}

// NewDtakoEventService サービスを作成
func NewDtakoEventService(eventRepo repository.DtakoEventRepository) *DtakoEventService {
	return &DtakoEventService{
		eventRepo: eventRepo,
	}
}

// GetEvent イベントを取得
func (s *DtakoEventService) GetEvent(ctx context.Context, req *pb.GetEventRequest) (*pb.Event, error) {
	event, err := s.eventRepo.GetByID(ctx, req.SrchId)
	if err != nil {
		return nil, fmt.Errorf("failed to get event: %w", err)
	}
	return convertEventToProto(event), nil
}

// GetByUnkoNo 運行NO指定でイベント一覧取得
func (s *DtakoEventService) GetByUnkoNo(ctx context.Context, req *pb.GetByUnkoNoRequest) (*pb.GetByUnkoNoResponse, error) {
	var startTime, endTime *time.Time

	if req.StartTime != nil {
		t := req.StartTime.AsTime()
		startTime = &t
	}

	if req.EndTime != nil {
		t := req.EndTime.AsTime()
		endTime = &t
	}

	events, err := s.eventRepo.GetByUnkoNo(ctx, req.UnkoNo, req.EventTypes, startTime, endTime)
	if err != nil {
		return nil, fmt.Errorf("failed to get events by unko_no: %w", err)
	}

	pbEvents := make([]*pb.Event, len(events))
	for i, event := range events {
		pbEvents[i] = convertEventToProto(event)
	}

	return &pb.GetByUnkoNoResponse{
		Events: pbEvents,
	}, nil
}

// AggregateByEventType イベント種別ごとの集計
func (s *DtakoEventService) AggregateByEventType(ctx context.Context, req *pb.AggregateByEventTypeRequest) (*pb.AggregateByEventTypeResponse, error) {
	var startTime, endTime *time.Time

	if req.StartTime != nil {
		t := req.StartTime.AsTime()
		startTime = &t
	}

	if req.EndTime != nil {
		t := req.EndTime.AsTime()
		endTime = &t
	}

	stats, err := s.eventRepo.AggregateByEventType(ctx, req.UnkoNo, startTime, endTime)
	if err != nil {
		return nil, fmt.Errorf("failed to aggregate by event type: %w", err)
	}

	aggregates := make([]*pb.EventTypeAggregate, 0, len(stats))

	// 全体の合計を計算
	total := &pb.EventTypeAggregate{
		EventType: "合計",
	}

	for _, stat := range stats {
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

// convertEventToProto モデルをProtoに変換
func convertEventToProto(event *models.DtakoEvent) *pb.Event {
	if event == nil {
		return nil
	}

	pbEvent := &pb.Event{
		SrchId:         event.SrchID,
		EventType:      event.EventName,
		UnkoNo:         event.UnkoNo,
		DriverId:       event.JomuinCD1,
		StartDatetime:  timestamppb.New(event.StartDateTime),
		StartLatitude:  event.StartGPSLat,
		StartLongitude: event.StartGPSLon,
		StartCityName:  event.StartCityName,
		CreatedAt:      timestamppb.New(event.CreatedAt),
		UpdatedAt:      timestamppb.New(event.UpdatedAt),
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
