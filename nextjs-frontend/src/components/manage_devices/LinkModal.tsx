import React from "react";
import { Edit, Link as LinkIcon, Save } from "lucide-react";
import CustomSelect from "@/components/ui/CustomSelect";
import { Device, Link } from "@/types/manage_devices";
import { Button } from "@/components/ui/Button";
import { Modal } from "@/components/ui/Modal";

interface LinkModalProps {
  isOpen: boolean;
  editingLink: Link | null;
  linkForm: Link;
  setLinkForm: React.Dispatch<React.SetStateAction<Link>>;
  devices: Device[];
  handleLinkAction: (e: React.FormEvent) => void;
  onClose: () => void;
}

export const LinkModal: React.FC<LinkModalProps> = ({
  isOpen, editingLink, linkForm, setLinkForm, devices, handleLinkAction, onClose,
}) => (
  <Modal
    isOpen={isOpen} onClose={onClose}
    title={editingLink ? "Edit Link" : "Add New Link"}
    icon={editingLink ? <Edit className="w-5 h-5 text-blue-400" /> : <LinkIcon className="w-5 h-5 text-blue-400" />}
    size="md"
    footer={
      <>
        <Button variant="secondary" type="button" onClick={onClose}>Cancel</Button>
        <Button variant="primary" form="link-form" type="submit">
          <Save className="w-4 h-4 mr-2" /> Save Link
        </Button>
      </>
    }
  >
    <form id="link-form" onSubmit={handleLinkAction} className="space-y-4">
      <div>
        <label className="block text-xs font-bold text-text-muted mb-1">Link Name *</label>
        <input type="text" required value={linkForm.name} placeholder="e.g. Core Switch to FW1"
          onChange={(e) => setLinkForm({ ...linkForm, name: e.target.value })}
          className="w-full bg-bg-card border border-border-subtle text-foreground rounded-2xl px-3 py-2 text-sm focus:outline-none focus:border-accent-primary" />
      </div>
      <div>
        <label className="block text-xs font-bold text-text-muted mb-1">IP/Subnet *</label>
        <input type="text" required value={linkForm.ip} placeholder="10.0.0.0/24 or IP"
          onChange={(e) => setLinkForm({ ...linkForm, ip: e.target.value })}
          className="w-full bg-bg-card border border-border-subtle text-foreground rounded-2xl px-3 py-2 text-sm focus:outline-none focus:border-accent-primary" />
      </div>
      <div>
        <label className="block text-xs font-bold text-text-muted mb-1">From Device *</label>
        <CustomSelect value={linkForm.from_device_id?.toString() || ""}
          onChange={(val) => setLinkForm({ ...linkForm, from_device_id: parseInt(val) })}
          options={[{ value: "", label: "Select from device..." }, ...devices.map((d) => ({ value: d.id.toString(), label: `${d.name} (${d.ip})` }))]}
          placeholder="Select from device..." />
      </div>
      <div>
        <label className="block text-xs font-bold text-text-muted mb-1">To Device *</label>
        <CustomSelect value={linkForm.to_device_id?.toString() || ""}
          onChange={(val) => setLinkForm({ ...linkForm, to_device_id: parseInt(val) })}
          options={[{ value: "", label: "Select to device..." }, ...devices.map((d) => ({ value: d.id.toString(), label: `${d.name} (${d.ip})` }))]}
          placeholder="Select to device..." />
      </div>
    </form>
  </Modal>
);
export default LinkModal;
