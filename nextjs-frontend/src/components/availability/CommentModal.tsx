import React from "react";
import { FileText, Save } from "lucide-react";
import CustomSelect from "@/components/ui/CustomSelect";
import { Period } from "@/types/availability";
import { Button } from "@/components/ui/Button";
import { Modal } from "@/components/ui/Modal";

interface CommentModalProps {
  isOpen: boolean;
  editingPeriod: Period;
  commentForm: {
    comments: string;
    action_taken: string;
    escalation_level: number;
    vendor_contacted: string;
    ticket_number: string;
    resolution_time: string;
  };
  setCommentForm: React.Dispatch<React.SetStateAction<{
    comments: string;
    action_taken: string;
    escalation_level: number;
    vendor_contacted: string;
    ticket_number: string;
    resolution_time: string;
  }>>;
  saveComment: (e: React.FormEvent) => void;
  formatDuration: (dur: number) => string;
  onClose: () => void;
}

export const CommentModal: React.FC<CommentModalProps> = ({
  isOpen, editingPeriod, commentForm, setCommentForm, saveComment, formatDuration, onClose,
}) => (
  <Modal
    isOpen={isOpen} onClose={onClose}
    title="Incident Report Details"
    icon={<FileText className="w-5 h-5" />}
    size="2xl"
    footer={
      <>
        <Button variant="secondary" type="button" onClick={onClose}>Cancel</Button>
        <Button variant="primary" form="comment-form" type="submit">
          <Save className="w-4 h-4 mr-2" /> Save Details
        </Button>
      </>
    }
  >
    {/* Event summary bar */}
    <div className="flex gap-4 text-xs font-mono bg-bg-card border border-border-subtle rounded-xl px-4 py-3 mb-6">
      <div className="text-foreground"><span className="text-text-muted">Event:</span> {editingPeriod.status.toUpperCase()}</div>
      <div className="text-foreground"><span className="text-text-muted">Start:</span> {editingPeriod.start}</div>
      <div className="text-foreground"><span className="text-text-muted">End:</span> {editingPeriod.end}</div>
      <div className="text-foreground"><span className="text-text-muted">Duration:</span> {formatDuration(editingPeriod.duration)}</div>
    </div>

    <form id="comment-form" onSubmit={saveComment} className="space-y-4">
      <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
        <div className="md:col-span-2">
          <label className="block text-xs font-bold text-text-muted mb-1">Issue Description / Comments</label>
          <textarea rows={3} value={commentForm.comments}
            onChange={(e) => setCommentForm({ ...commentForm, comments: e.target.value })}
            className="w-full bg-bg-card border border-border-subtle text-foreground rounded-2xl px-3 py-2 text-sm focus:outline-none focus:border-accent-primary"
            placeholder="Describe the issue..." />
        </div>
        <div className="md:col-span-2">
          <label className="block text-xs font-bold text-text-muted mb-1">Action Taken / Resolution</label>
          <textarea rows={3} value={commentForm.action_taken}
            onChange={(e) => setCommentForm({ ...commentForm, action_taken: e.target.value })}
            className="w-full bg-bg-card border border-border-subtle text-foreground rounded-2xl px-3 py-2 text-sm focus:outline-none focus:border-accent-primary"
            placeholder="What was done to resolve it..." />
        </div>
        <div>
          <label className="block text-xs font-bold text-text-muted mb-1">Escalation Level</label>
          <CustomSelect value={commentForm.escalation_level.toString()}
            onChange={(val) => setCommentForm({ ...commentForm, escalation_level: parseInt(val) })}
            options={[
              { value: "0", label: "0 - No Escalation" }, { value: "1", label: "1 - L1 Support" },
              { value: "2", label: "2 - L2 Support" }, { value: "3", label: "3 - Vendor / Management" },
            ]} />
        </div>
        <div>
          <label className="block text-xs font-bold text-text-muted mb-1">Vendor Contacted</label>
          <input type="text" value={commentForm.vendor_contacted} placeholder="e.g. Cisco TAC, ISP"
            onChange={(e) => setCommentForm({ ...commentForm, vendor_contacted: e.target.value })}
            className="w-full bg-bg-card border border-border-subtle text-foreground rounded-2xl px-3 py-2 text-sm focus:outline-none focus:border-accent-primary" />
        </div>
        <div>
          <label className="block text-xs font-bold text-text-muted mb-1">Ticket Number</label>
          <input type="text" value={commentForm.ticket_number} placeholder="INC-12345"
            onChange={(e) => setCommentForm({ ...commentForm, ticket_number: e.target.value })}
            className="w-full bg-bg-card border border-border-subtle text-foreground rounded-2xl px-3 py-2 text-sm focus:outline-none focus:border-accent-primary" />
        </div>
        <div>
          <label className="block text-xs font-bold text-text-muted mb-1">Resolution Time</label>
          <input type="datetime-local" value={commentForm.resolution_time}
            onChange={(e) => setCommentForm({ ...commentForm, resolution_time: e.target.value })}
            className="w-full bg-bg-card border border-border-subtle text-foreground rounded-2xl px-3 py-2 text-sm focus:outline-none focus:border-accent-primary" />
        </div>
      </div>
    </form>
  </Modal>
);
export default CommentModal;
