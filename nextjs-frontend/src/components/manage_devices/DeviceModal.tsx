import React from "react";
import { Plus, Edit, Save } from "lucide-react";
import CustomSelect from "@/components/ui/CustomSelect";
import { Device } from "@/types/manage_devices";
import { Button } from "@/components/ui/Button";
import { Modal } from "@/components/ui/Modal";

interface DeviceModalProps {
  isOpen: boolean;
  editingDevice: Device | null;
  deviceForm: Device;
  setDeviceForm: React.Dispatch<React.SetStateAction<Device>>;
  customModel: string;
  setCustomModel: (model: string) => void;
  models: Record<string, string[]>;
  handleDeviceAction: (e: React.FormEvent) => void;
  onClose: () => void;
}

export const DeviceModal: React.FC<DeviceModalProps> = ({
  isOpen, editingDevice, deviceForm, setDeviceForm,
  customModel, setCustomModel, models, handleDeviceAction, onClose,
}) => (
  <Modal
    isOpen={isOpen} onClose={onClose}
    title={editingDevice ? "Edit Device" : "Add New Device"}
    icon={editingDevice ? <Edit className="w-5 h-5 text-blue-400" /> : <Plus className="w-5 h-5" />}
    size="3xl"
    footer={
      <>
        <Button variant="secondary" type="button" onClick={onClose}>Cancel</Button>
        <Button variant="primary" form="device-form" type="submit">
          <Save className="w-4 h-4 mr-2" /> Save Device
        </Button>
      </>
    }
  >
    <form id="device-form" onSubmit={handleDeviceAction}>
      <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
        <div className="space-y-4">
          <h4 className="text-accent-primary font-bold border-b border-border-subtle pb-2">General Info</h4>
          <div>
            <label className="block text-xs font-bold text-text-muted mb-1">Device Name *</label>
            <input type="text" required value={deviceForm.name}
              onChange={(e) => setDeviceForm({ ...deviceForm, name: e.target.value })}
              className="w-full bg-bg-card border border-border-subtle text-foreground rounded-2xl px-3 py-2 text-sm focus:outline-none focus:border-accent-primary" />
          </div>
          <div>
            <label className="block text-xs font-bold text-text-muted mb-1">IP Address *</label>
            <input type="text" required value={deviceForm.ip} placeholder="192.168.1.1"
              onChange={(e) => setDeviceForm({ ...deviceForm, ip: e.target.value })}
              className="w-full bg-bg-card border border-border-subtle text-foreground rounded-2xl px-3 py-2 text-sm focus:outline-none focus:border-accent-primary" />
          </div>
          <div>
            <label className="block text-xs font-bold text-text-muted mb-1">Type *</label>
            <CustomSelect value={deviceForm.type}
              onChange={(val) => { setDeviceForm({ ...deviceForm, type: val, model: "" }); setCustomModel(""); }}
              options={[
                { value: "server", label: "Server" }, { value: "switch", label: "Switch" },
                { value: "firewall", label: "Firewall" }, { value: "router", label: "Router" },
                { value: "wireless", label: "Wireless AP" }, { value: "loadbalancer", label: "Load Balancer" },
                { value: "storage", label: "Storage/NAS" }, { value: "iot", label: "IoT/Other" },
              ]} />
          </div>
          <div>
            <label className="block text-xs font-bold text-text-muted mb-1">Model *</label>
            <CustomSelect value={deviceForm.model}
              onChange={(val) => setDeviceForm({ ...deviceForm, model: val })}
              options={[{ value: "", label: "Select a model" },
                ...(models[deviceForm.type] || models["others"] || []).map((m) => ({ value: m, label: m }))]}
              placeholder="Select a model" />
            {deviceForm.model === "Other" && (
              <input type="text" placeholder="Enter custom model..." required value={customModel}
                onChange={(e) => setCustomModel(e.target.value)}
                className="w-full bg-bg-card border border-border-subtle text-foreground rounded-2xl px-3 py-2 text-sm focus:outline-none focus:border-accent-primary mt-2" />
            )}
          </div>
        </div>

        <div className="space-y-4">
          <h4 className="text-accent-primary font-bold border-b border-border-subtle pb-2">Location & Contact</h4>
          <div className="flex gap-4">
            <div className="flex-1">
              <label className="block text-xs font-bold text-text-muted mb-1">City</label>
              <input type="text" value={deviceForm.city}
                onChange={(e) => setDeviceForm({ ...deviceForm, city: e.target.value })}
                className="w-full bg-bg-card border border-border-subtle text-foreground rounded-2xl px-3 py-2 text-sm focus:outline-none focus:border-accent-primary" />
            </div>
            <div className="flex-1">
              <label className="block text-xs font-bold text-text-muted mb-1">Country</label>
              <input type="text" value={deviceForm.country}
                onChange={(e) => setDeviceForm({ ...deviceForm, country: e.target.value })}
                className="w-full bg-bg-card border border-border-subtle text-foreground rounded-2xl px-3 py-2 text-sm focus:outline-none focus:border-accent-primary" />
            </div>
          </div>
          <div>
            <label className="block text-xs font-bold text-text-muted mb-1">Sub Office</label>
            <input type="text" value={deviceForm.sub_office}
              onChange={(e) => setDeviceForm({ ...deviceForm, sub_office: e.target.value })}
              className="w-full bg-bg-card border border-border-subtle text-foreground rounded-2xl px-3 py-2 text-sm focus:outline-none focus:border-accent-primary" />
          </div>
          <div>
            <label className="block text-xs font-bold text-text-muted mb-1">Contact Number</label>
            <input type="text" value={deviceForm.contact_number}
              onChange={(e) => setDeviceForm({ ...deviceForm, contact_number: e.target.value })}
              className="w-full bg-bg-card border border-border-subtle text-foreground rounded-2xl px-3 py-2 text-sm focus:outline-none focus:border-accent-primary" />
          </div>
          <div>
            <label className="block text-xs font-bold text-text-muted mb-1">Email</label>
            <input type="email" value={deviceForm.email}
              onChange={(e) => setDeviceForm({ ...deviceForm, email: e.target.value })}
              className="w-full bg-bg-card border border-border-subtle text-foreground rounded-2xl px-3 py-2 text-sm focus:outline-none focus:border-accent-primary" />
          </div>
        </div>

        <div className="space-y-4 md:col-span-2">
          <h4 className="text-accent-primary font-bold border-b border-border-subtle pb-2">SNMP Configuration</h4>
          <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
            <div>
              <label className="block text-xs font-bold text-text-muted mb-1">SNMP Community</label>
              <input type="text" value={deviceForm.snmp_community} placeholder="public"
                onChange={(e) => setDeviceForm({ ...deviceForm, snmp_community: e.target.value })}
                className="w-full bg-bg-card border border-border-subtle text-foreground rounded-2xl px-3 py-2 text-sm focus:outline-none focus:border-accent-primary" />
            </div>
            <div>
              <label className="block text-xs font-bold text-text-muted mb-1">SNMP Version</label>
              <CustomSelect value={deviceForm.snmp_version}
                onChange={(val) => setDeviceForm({ ...deviceForm, snmp_version: val })}
                options={[{ value: "1", label: "v1" }, { value: "2c", label: "v2c" }, { value: "3", label: "v3" }]} />
            </div>
            <div>
              <label className="block text-xs font-bold text-text-muted mb-1">SNMP Port</label>
              <input type="number" value={deviceForm.snmp_port}
                onChange={(e) => setDeviceForm({ ...deviceForm, snmp_port: parseInt(e.target.value) || 161 })}
                className="w-full bg-bg-card border border-border-subtle text-foreground rounded-2xl px-3 py-2 text-sm focus:outline-none focus:border-accent-primary" />
            </div>
          </div>
        </div>
      </div>
    </form>
  </Modal>
);
export default DeviceModal;
