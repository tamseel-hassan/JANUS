import React from "react";
import { Line } from "react-chartjs-2";
import { Activity } from "lucide-react";
import { Report } from "@/types/availability";

interface AvailabilityChartProps {
  report: Report;
  chartData: any;
  chartOptions: any;
}

export const AvailabilityChart: React.FC<AvailabilityChartProps> = ({
  report,
  chartData,
  chartOptions,
}) => {
  return (
    <div className="bg-bg-raised border border-border-subtle rounded-2xl overflow-hidden p-6">
      <h4 className="text-foreground font-bold mb-4 flex items-center">
        <Activity className="w-5 h-5 mr-2 text-accent-primary" /> RTT Timeline
      </h4>
      <div className="h-64">
        {chartData && <Line data={chartData} options={chartOptions} />}
      </div>
    </div>
  );
};
export default AvailabilityChart;
