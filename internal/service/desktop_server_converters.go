package service

import (
	"fmt"
	"time"

	"github.com/yhonda-ohishi/dtako_events/internal/models"
	pb "github.com/yhonda-ohishi/dtako_events/proto"
	"google.golang.org/protobuf/types/known/timestamppb"
)

// desktop-server用の変換関数

func (s *DtakoRowService) convertEtcMeisaiDataFromDesktop(etcMeisai []*models.EtcMeisai) []*pb.EtcMeisaiData {
	result := make([]*pb.EtcMeisaiData, 0, len(etcMeisai))
	for _, etc := range etcMeisai {
		result = append(result, &pb.EtcMeisaiData{
			Id:         fmt.Sprintf("%d", etc.ID),
			DtakoRowId: etc.DtakoRowID,
			DateFr:     timestamppb.New(etc.DateFr),
			DateTo:     timestamppb.New(etc.DateTo),
			Kingaku:    int32(etc.Kingaku),
		})
	}
	return result
}

func (s *DtakoRowService) convertUriageKeihiDataFromDesktop(uriageKeihi []*models.DtakoUriageKeihi) []*pb.UriageKeihiData {
	result := make([]*pb.UriageKeihiData, 0, len(uriageKeihi))
	for _, uk := range uriageKeihi {
		result = append(result, &pb.UriageKeihiData{
			SrchId:     uk.SrchID,
			DtakoRowId: uk.DtakoRowID,
			KeihiC:     int32(uk.KeihiC),
			Datetime:   timestamppb.New(uk.Datetime),
			Kingaku:    int32(uk.Kingaku),
		})
	}
	return result
}

func (s *DtakoRowService) convertFerryDataFromDesktop(ferryRows []*models.DtakoFerryRow) []*pb.FerryData {
	result := make([]*pb.FerryData, 0, len(ferryRows))
	for _, ferry := range ferryRows {
		endTime := timestamppb.New(time.Time{})
		if ferry.EndDateTime != nil {
			endTime = timestamppb.New(*ferry.EndDateTime)
		}
		result = append(result, &pb.FerryData{
			Id:            fmt.Sprintf("%d", ferry.ID),
			UnkoNo:        ferry.UnkoNo,
			StartDatetime: timestamppb.New(ferry.StartDateTime),
			EndDatetime:   endTime,
			FerryName:     ferry.FerryName,
			Kingaku:       int32(ferry.Kingaku),
		})
	}
	return result
}

func (s *DtakoRowService) convertRyohiRowsFromDesktop(ryohiRows []*models.RyohiRow) []*pb.RyohiRow {
	result := make([]*pb.RyohiRow, 0, len(ryohiRows))
	for _, ryohi := range ryohiRows {
		result = append(result, &pb.RyohiRow{
			Id:         uint32(ryohi.ID),
			UnkoNo:     ryohi.UnkoNo,
			TsumiDate:  ryohi.TsumiDate,
			OroshiDate: ryohi.OroshiDate,
			Tokuisaki:  ryohi.Tokuisaki,
			Status:     ryohi.Status,
			CreatedAt:  timestamppb.New(ryohi.CreatedAt),
			UpdatedAt:  timestamppb.New(ryohi.UpdatedAt),
		})
	}
	return result
}
