import React from "react";
import { Eye, EyeOff } from "lucide-react";
import { Device } from "@/types/maps";
import { Button } from "@/components/ui/Button";
import { Modal } from "@/components/ui/Modal";

interface VisibilityModalProps {
  isOpen: boolean;
  devices: Device[];
  toggleVisibility: (deviceId: number, currentVisible: boolean) => void;
  onClose: () => void;
}

export const VisibilityModal: React.FC<VisibilityModalProps> = ({
  isOpen, devices, toggleVisibility, onClose,
}) => (
  <Modal
    isOpen={isOpen} onClose={onClose}
    title="Device Visibility"
    icon={<Eye className="w-5 h-5" />}
    size="2xl"
    footer={
      <Button variant="outline" onClick={onClose} className="border-blue-500/30 text-blue-400 hover:bg-blue-500/10">
        Done
      </Button>
    }
  >
    <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
      {devices.map((d) => (
        <div key={d.id} className="bg-bg-card border border-border-subtle rounded-2xl p-3 flex justify-between items-center">
          <div>
            <div className="text-foreground font-bold">{d.name}</div>
            <div className="text-xs text-text-muted">{d.ip} ({d.type})</div>
          </div>
          <Button
            variant={d.is_visible ? "outline-success" : "outline"}
            size="icon"
            onClick={() => toggleVisibility(d.id, d.is_visible)}
            className={!d.is_visible ? "opacity-50" : ""}
          >
            {d.is_visible ? <Eye className="w-5 h-5" /> : <EyeOff className="w-5 h-5" />}
          </Button>
        </div>
      ))}
    </div>
  </Modal>
);

export default VisibilityModal;
