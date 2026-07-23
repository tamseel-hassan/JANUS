"use client";

import { useEffect, useState, useRef } from "react";
import { Modal } from "@/components/ui/Modal";
import { Button } from "@/components/ui/Button";
import { LoadingSpinner } from "@/components/ui/LoadingSpinner";
import { safeFetch } from "@/lib/safeFetch";
import { Incident, IncidentComment, Observable, IncidentHistory, SocOptions } from "@/types/soc";
import { FileArchive, AlertCircle, Clock, Trash2, Link as LinkIcon, Send, Download } from "lucide-react";
import CustomSelect from "@/components/ui/CustomSelect";
import { notify, promptService } from "@/services/feedback/feedbackService";
import { socService } from "@/services/soc/socService";

interface IncidentModalProps {
  incidentId: number;
  isOpen: boolean;
  onClose: () => void;
  onUpdate: () => void;
  isAdmin?: boolean;
}

export default function IncidentModal({ incidentId, isOpen, onClose, onUpdate, isAdmin = false }: IncidentModalProps) {
  const [loading, setLoading] = useState(true);
  const [incident, setIncident] = useState<Incident | null>(null);
  const [comments, setComments] = useState<IncidentComment[]>([]);
  const [observables, setObservables] = useState<Observable[]>([]);
  const [history, setHistory] = useState<IncidentHistory[]>([]);
  const [options, setOptions] = useState<SocOptions>({ users: [], devices: [] });
  
  const [commentText, setCommentText] = useState("");
  const [submittingComment, setSubmittingComment] = useState(false);
  const commentsEndRef = useRef<HTMLDivElement>(null);

  useEffect(() => {
    if (isOpen && incidentId) {
      fetchData();
    } else {
      setIncident(null);
    }
  }, [isOpen, incidentId]);

  useEffect(() => {
    if (commentsEndRef.current) {
      commentsEndRef.current.scrollIntoView({ behavior: "smooth" });
    }
  }, [comments]);

  const fetchData = async () => {
    setLoading(true);
    const [optData, detailData] = await Promise.all([
      socService.getOptions(),
      socService.getIncidentDetails(incidentId)
    ]);
    
    if (optData) setOptions(optData);
    if (detailData && detailData.incident) {
      setIncident(detailData.incident);
      setComments(detailData.comments || []);
      setObservables(detailData.observables || []);
      setHistory(detailData.history || []);
    }
    setLoading(false);
  };

  const handleAction = async (action: string, data: Record<string, string>) => {
    const formData = new FormData();
    formData.append("action", action);
    formData.append("id", incidentId.toString());
    Object.entries(data).forEach(([key, val]) => formData.append(key, val));
    
    try {
      const result = await socService.executeAction(formData);
      if (result?.success) {
        notify.success(typeof result.success === "string" ? result.success : (result.message || "Action completed successfully"));
        fetchData();
        onUpdate(); // refresh parent table
        if (action === "delete") onClose();
      } else if (result?.error) {
        notify.error(result.error);
      }
    } catch(err: any) {
      notify.error(err.message || "Action failed");
      console.error("Action failed", err);
    }
  };

  const submitComment = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!commentText.trim()) return;
    setSubmittingComment(true);
    await handleAction("add_comment", { comment: commentText });
    setCommentText("");
    setSubmittingComment(false);
  };

  if (!isOpen) return null;

  return (
    <Modal isOpen={isOpen} onClose={onClose} title={incident ? `INC-${incident.id}: ${incident.title}` : "Loading..."}>
      {loading || !incident ? (
        <div className="p-12 flex justify-center"><LoadingSpinner size="lg" /></div>
      ) : (
        <div className="flex flex-col md:flex-row h-full max-h-[80vh] overflow-hidden gap-0">
          
          {/* Left panel: details */}
          <div className="flex-1 overflow-y-auto custom-scrollbar p-6 space-y-6 border-r border-border-subtle bg-bg-base/50">
            <div className="flex flex-wrap gap-2 mb-4">
              <span className={`px-2.5 py-1 rounded-full text-xs font-semibold ${
                incident.severity === 'critical' ? 'bg-red-500/20 text-red-400 border border-red-500/30' :
                incident.severity === 'high' ? 'bg-orange-500/20 text-orange-400 border border-orange-500/30' :
                incident.severity === 'medium' ? 'bg-yellow-500/20 text-yellow-400 border border-yellow-500/30' :
                'bg-blue-500/20 text-blue-400 border border-blue-500/30'
              }`}>
                {incident.severity.toUpperCase()} SEVERITY
              </span>
              <span className={`px-2.5 py-1 rounded-full text-xs font-semibold ${
                incident.status === 'open' ? 'bg-emerald-500/20 text-emerald-400 border border-emerald-500/30' :
                incident.status === 'in_progress' ? 'bg-blue-500/20 text-blue-400 border border-blue-500/30' :
                'bg-slate-500/20 text-slate-400 border border-slate-500/30'
              }`}>
                {incident.status.replace('_', ' ').toUpperCase()}
              </span>
              <span className="px-2.5 py-1 rounded-full text-xs font-semibold bg-bg-card border border-border-subtle text-text-muted">
                {incident.type}
              </span>
            </div>

            <div>
              <h4 className="text-xs uppercase font-semibold text-text-muted mb-2">Description</h4>
              <p className="text-sm text-foreground bg-bg-card border border-border-subtle rounded-xl p-4 whitespace-pre-wrap leading-relaxed">
                {incident.description || "No description provided."}
              </p>
            </div>

            <div className="grid grid-cols-2 gap-4">
              <div className="bg-bg-card border border-border-subtle rounded-xl p-4">
                <span className="block text-xs text-text-muted mb-1">Reporter</span>
                <span className="text-sm font-medium">{incident.reporter_name || 'System'}</span>
              </div>
              <div className="bg-bg-card border border-border-subtle rounded-xl p-4">
                <span className="block text-xs text-text-muted mb-1">Created At</span>
                <span className="text-sm font-medium">{new Date(incident.created_at).toLocaleString()}</span>
              </div>
              <div className="bg-bg-card border border-border-subtle rounded-xl p-4">
                <span className="block text-xs text-text-muted mb-1">Assigned To</span>
                <div className="mt-1">
                  <CustomSelect
                    value={incident.assigned_to?.toString() || ""}
                    onChange={(val) => handleAction("assign", { assigned_to: val })}
                    options={[
                      { value: "", label: "Unassigned" },
                      ...options.users.map(u => ({ value: u.id.toString(), label: u.username }))
                    ]}
                  />
                </div>
              </div>
              <div className="bg-bg-card border border-border-subtle rounded-xl p-4">
                <span className="block text-xs text-text-muted mb-1">Status</span>
                <div className="mt-1">
                  <CustomSelect
                    value={incident.status}
                    onChange={(val) => handleAction("change_status", { new_status: val })}
                    options={[
                      { value: "open", label: "Open" },
                      { value: "in_progress", label: "In Progress" },
                      { value: "resolved", label: "Resolved" },
                      { value: "closed", label: "Closed" },
                    ]}
                  />
                </div>
              </div>
            </div>

            {incident.attachment && (
              <div>
                <h4 className="text-xs uppercase font-semibold text-text-muted mb-2">Attachment</h4>
                <a href={incident.attachment.replace(/^.*?\/uploads\//, '/uploads/')} target="_blank" className="inline-flex items-center gap-2 px-4 py-2 bg-bg-card border border-border-subtle rounded-xl text-sm hover:text-accent-primary transition-colors">
                  <FileArchive className="w-4 h-4" /> Download File
                </a>
              </div>
            )}

            {observables.length > 0 && (
              <div>
                <h4 className="text-xs uppercase font-semibold text-text-muted mb-2">Indicators (Observables)</h4>
                <div className="space-y-2">
                  {observables.map(obs => (
                    <div key={obs.id} className="flex flex-wrap items-center gap-2 bg-bg-card border border-border-subtle rounded-xl p-3 text-sm">
                      <span className="px-2 py-0.5 rounded-md bg-accent-primary/10 text-accent-primary text-xs font-mono">{obs.type}</span>
                      <span className="font-mono text-foreground font-medium">{obs.value}</span>
                      {obs.notes && <span className="text-text-muted text-xs border-l border-border-subtle pl-2 ml-2">{obs.notes}</span>}
                    </div>
                  ))}
                </div>
              </div>
            )}

            {history.length > 0 && (
              <div>
                <h4 className="text-xs uppercase font-semibold text-text-muted mb-2">History</h4>
                <div className="space-y-2 text-xs text-text-muted">
                  {history.map(h => (
                    <div key={h.id} className="flex gap-2">
                      <Clock className="w-3.5 h-3.5 shrink-0 mt-0.5" />
                      <span>
                        <strong className="text-foreground">{h.username || 'System'}</strong> changed 
                        <code className="mx-1 bg-bg-card px-1 rounded">{h.field}</code> 
                        from <code className="mx-1 bg-bg-card px-1 rounded">{h.old_value}</code> 
                        to <code className="mx-1 bg-bg-card px-1 rounded">{h.new_value}</code>
                        <span className="ml-2 opacity-50">{new Date(h.changed_at).toLocaleString()}</span>
                      </span>
                    </div>
                  ))}
                </div>
              </div>
            )}
            
            {isAdmin && (
              <div className="pt-4 border-t border-border-subtle mt-8">
                <Button variant="danger" onClick={async () => {
                  const ok = await promptService.confirm({
                    title: "Delete Incident",
                    description: "Are you sure you want to completely delete this incident?",
                    variant: "destructive",
                    confirmText: "Delete"
                  });
                  if (ok) handleAction("delete", {});
                }}>
                  <Trash2 className="w-4 h-4 mr-2" /> Delete Incident
                </Button>
              </div>
            )}
          </div>

          {/* Right panel: comments */}
          <div className="w-full md:w-80 flex flex-col bg-bg-card border-l border-border-subtle">
            <div className="p-4 border-b border-border-subtle bg-bg-base/30">
              <h4 className="font-semibold text-foreground text-sm">Comments & Activity</h4>
            </div>
            
            <div className="flex-1 overflow-y-auto p-4 space-y-4 custom-scrollbar">
              {comments.length === 0 ? (
                <div className="text-center text-text-muted text-sm pt-8 opacity-50">No comments yet.</div>
              ) : (
                comments.map(c => (
                  <div key={c.id} className="bg-bg-darker border border-border-subtle rounded-xl p-3">
                    <div className="flex justify-between items-center mb-2 text-xs">
                      <strong className="text-foreground">{c.username || 'System'}</strong>
                      <span className="text-text-muted">{new Date(c.created_at).toLocaleDateString()}</span>
                    </div>
                    <p className="text-sm text-foreground whitespace-pre-wrap">{c.comment}</p>
                  </div>
                ))
              )}
              <div ref={commentsEndRef} />
            </div>

            <div className="p-4 border-t border-border-subtle bg-bg-base/30">
              <form onSubmit={submitComment} className="flex gap-2">
                <input 
                  type="text" 
                  value={commentText}
                  onChange={(e) => setCommentText(e.target.value)}
                  placeholder="Type a comment..." 
                  className="flex-1 bg-bg-input border border-border-subtle rounded-xl px-3 py-2 text-sm text-foreground focus:outline-none focus:border-accent-primary"
                />
                <Button type="submit" disabled={submittingComment || !commentText.trim()} className="px-3 shrink-0">
                  <Send className="w-4 h-4" />
                </Button>
              </form>
            </div>
          </div>
          
        </div>
      )}
    </Modal>
  );
}
