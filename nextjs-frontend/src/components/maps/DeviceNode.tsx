import React from "react";
import { Server, Shield, Network } from "lucide-react";
import { Device } from "@/types/maps";

interface DeviceNodeProps {
  device: Device;
  draggingNode: number | null;
  handlePointerDown: (e: React.PointerEvent<HTMLDivElement>, id: number) => void;
  handlePointerMove: (e: React.PointerEvent<HTMLDivElement>, id: number) => void;
  handlePointerUp: (e: React.PointerEvent<HTMLDivElement>) => void;
}

const getDeviceIcon = (type: string, isUp: boolean) => {
  const className = `w-6 h-6 transition-colors duration-300 ${
    isUp ? "text-accent-primary group-hover:text-foreground" : "text-red-400 group-hover:text-foreground"
  }`;
  switch (type?.toLowerCase()) {
    case "server":
      return <Server className={className} />;
    case "firewall":
      return <Shield className={className} />;
    case "switch":
    case "router":
      return <Network className={className} />;
    default:
      return <Server className={className} />;
  }
};

export const DeviceNode: React.FC<DeviceNodeProps> = ({
  device,
  draggingNode,
  handlePointerDown,
  handlePointerMove,
  handlePointerUp,
}) => {
  const isUp = device.status === "up";
  const colorClass = isUp
    ? "bg-green-500 shadow-[0_0_8px_#22c55e]"
    : "bg-red-500 animate-pulse shadow-[0_0_8px_#ef4444]";
  const isDragging = draggingNode === device.id;

  return (
    <div
      className={`absolute z-10 w-20 cursor-pointer flex flex-col items-center justify-center select-none group transition-all duration-75 ${
        isDragging
          ? "scale-105 z-30 filter drop-shadow-[0_10px_15px_rgba(0,0,0,0.5)]"
          : "hover:scale-105"
      }`}
      style={{ left: Number(device.x), top: Number(device.y), touchAction: "none" }}
      onPointerDown={(e) => handlePointerDown(e, device.id)}
      onPointerMove={(e) => handlePointerMove(e, device.id)}
      onPointerUp={handlePointerUp}
      onPointerCancel={handlePointerUp}
    >
      <div
        className={`relative w-12 h-12 bg-bg-card/90 backdrop-blur-md rounded-2xl border-2 flex items-center justify-center transition-all duration-300 shadow-md ${
          isUp
            ? "border-green-500/20 group-hover:border-green-500/60 shadow-green-500/5 group-hover:bg-green-500/10"
            : "border-red-500/40 group-hover:border-red-500 shadow-red-500/5 group-hover:bg-red-500/10"
        }`}
      >
        {getDeviceIcon(device.type, isUp)}
        <span className={`absolute -bottom-1 -right-1 w-3.5 h-3.5 rounded-full border-2 border-bg-darkest ${colorClass}`}></span>
      </div>
      <div className="mt-2 bg-bg-card/85 backdrop-blur-md px-2 py-0.5 rounded-xl text-[10px] text-foreground font-semibold max-w-[90px] text-center truncate border border-border-subtle/50 shadow-sm transition-all duration-300 group-hover:border-accent-primary/60 group-hover:bg-bg-card">
        {device.name}
      </div>
    </div>
  );
};
