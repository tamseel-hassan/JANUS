"use client";

import { useEffect, useState, useRef } from "react";
import { AlertTriangle, Send, Plus, Trash2, Paperclip } from "lucide-react";
import { LoadingSpinner } from "@/components/ui/LoadingSpinner";
import { AuthError } from "@/components/ui/AuthError";
import { PageHeader } from "@/components/ui/PageHeader";
import { SectionCard } from "@/components/ui/SectionCard";
import CustomSelect from "@/components/ui/CustomSelect";
import { Button } from "@/components/ui/Button";
import { socService } from "@/services/soc/socService";
import { SocOptions, Observable } from "@/types/soc";
import { useRouter } from "next/navigation";

export default function ReportIncident() {
  const router = useRouter();
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [submitting, setSubmitting] = useState(false);
  const [options, setOptions] = useState<SocOptions>({ users: [], devices: [] });
  
  const formRef = useRef<HTMLFormElement>(null);

  const [title, setTitle] = useState("");
  const [description, setDescription] = useState("");
  const [type, setType] = useState("");
  const [subcategory, setSubcategory] = useState("");
  const [priority, setPriority] = useState("medium");
  const [deviceId, setDeviceId] = useState("");
  const [assignedTo, setAssignedTo] = useState("");
  const [observables, setObservables] = useState<Partial<Observable>[]>([]);
  
  useEffect(() => {
    fetchOptions();
  }, []);

  const fetchOptions = async () => {
    const data = await socService.getOptions();
    if (data) {
      setOptions(data);
      setError(null);
    } else {
      setError("Failed to load users and devices.");
    }
    setLoading(false);
  };

  const handleAddObservable = () => {
    setObservables([...observables, { type: "ip", value: "", notes: "" }]);
  };

  const handleRemoveObservable = (index: number) => {
    setObservables(observables.filter((_, idx) => idx !== index));
  };

  const handleObservableChange = (index: number, key: keyof Observable, val: string) => {
    const next = [...observables];
    next[index] = { ...next[index], [key]: val };
    setObservables(next);
  };

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    setSubmitting(true);
    
    if (!formRef.current) return;
    
    const formData = new FormData(formRef.current);
    formData.append("action", "create");
    formData.append("priority", priority);
    formData.append("device_id", deviceId);
    formData.append("assigned_to", assignedTo);
    
    // Add observables to formData
    observables.forEach((obs, idx) => {
      formData.append(`observables[${idx}][type]`, obs.type || "");
      formData.append(`observables[${idx}][value]`, obs.value || "");
      formData.append(`observables[${idx}][description]`, obs.notes || "");
    });

    try {
      const data = await socService.reportIncident(formData);
      if (data?.error) {
        throw new Error(data.error);
      } else {
        router.push("/my_tasks?success=1");
      }
    } catch(err: any) {
      setError(err.message || "Failed to submit incident.");
      setSubmitting(false);
    }
  };

  if (loading) return <LoadingSpinner size="lg" className="mt-20" />;
  if (error && !options.users.length) return <AuthError />;

  return (
    <div className="space-y-6 animate-in fade-in slide-in-from-bottom-4 duration-500">
      <PageHeader 
        title={
          <span className="flex items-center gap-3">
            <AlertTriangle className="w-6 h-6 text-accent-primary" />
            Report Security Incident
          </span>
        }
        subtitle="Create a new ticket for security analysis and investigation."
      />

      {error && <div className="bg-red-500/10 border border-red-500/20 text-red-400 p-4 rounded-xl text-sm">{error}</div>}

      <form ref={formRef} onSubmit={handleSubmit} className="space-y-6">
        <SectionCard header={<h3 className="font-semibold text-foreground">Incident Details</h3>}>
          <div className="p-6 grid grid-cols-1 md:grid-cols-2 gap-6">
            <div className="md:col-span-2">
              <label className="block text-sm font-medium text-text-muted mb-2">Title *</label>
              <input 
                required 
                type="text" 
                name="title"
                value={title}
                onChange={(e) => setTitle(e.target.value)}
                className="w-full bg-bg-card border border-border-subtle rounded-2xl px-4 py-2.5 text-sm text-foreground focus:outline-none focus:border-accent-primary" 
                placeholder="Brief summary of the incident..." 
              />
            </div>
            
            <div className="md:col-span-2">
              <label className="block text-sm font-medium text-text-muted mb-2">Description *</label>
              <textarea 
                required 
                name="description"
                value={description}
                onChange={(e) => setDescription(e.target.value)}
                rows={4} 
                className="w-full bg-bg-card border border-border-subtle rounded-2xl px-4 py-2.5 text-sm text-foreground focus:outline-none focus:border-accent-primary" 
                placeholder="Detailed description..."
              ></textarea>
            </div>

            <div>
              <label className="block text-sm font-medium text-text-muted mb-2">Incident Type *</label>
              <input 
                required 
                type="text" 
                name="type"
                value={type}
                onChange={(e) => setType(e.target.value)}
                className="w-full bg-bg-card border border-border-subtle rounded-2xl px-4 py-2.5 text-sm text-foreground focus:outline-none focus:border-accent-primary" 
                placeholder="e.g. Malware, Phishing..." 
              />
            </div>

            <div>
              <label className="block text-sm font-medium text-text-muted mb-2">Subcategory</label>
              <input 
                type="text" 
                name="subcategory"
                value={subcategory}
                onChange={(e) => setSubcategory(e.target.value)}
                className="w-full bg-bg-card border border-border-subtle rounded-2xl px-4 py-2.5 text-sm text-foreground focus:outline-none focus:border-accent-primary" 
                placeholder="e.g. Ransomware, Spear Phishing..." 
              />
            </div>

            <div>
              <label className="block text-sm font-medium text-text-muted mb-2">Priority Level</label>
              <CustomSelect
                value={priority}
                onChange={setPriority}
                options={[
                  { value: "low", label: "Low (14 days SLA)" },
                  { value: "medium", label: "Medium (7 days SLA)" },
                  { value: "high", label: "High (3 days SLA)" },
                  { value: "critical", label: "Critical (24 hours SLA)" },
                ]}
              />
            </div>

            <div>
              <label className="block text-sm font-medium text-text-muted mb-2">Related Device (Optional)</label>
              <CustomSelect
                value={deviceId}
                onChange={setDeviceId}
                options={[
                  { value: "", label: "None" },
                  ...options.devices.map(d => ({ value: d.id.toString(), label: `${d.name} (${d.ip})` }))
                ]}
              />
            </div>

            <div>
              <label className="block text-sm font-medium text-text-muted mb-2">Assign To (Optional)</label>
              <CustomSelect
                value={assignedTo}
                onChange={setAssignedTo}
                options={[
                  { value: "", label: "Unassigned" },
                  ...options.users.map(u => ({ value: u.id.toString(), label: u.username }))
                ]}
              />
            </div>
            
            <div>
              <label className="block text-sm font-medium text-text-muted mb-2">Attachment (Optional)</label>
              <div className="flex items-center gap-2">
                <Paperclip className="w-4 h-4 text-text-muted" />
                <input 
                  type="file" 
                  name="attachment" 
                  className="w-full bg-transparent border-0 text-sm text-foreground file:mr-4 file:py-1.5 file:px-4 file:rounded-full file:border-0 file:text-xs file:font-semibold file:bg-bg-darker file:text-text-muted hover:file:bg-bg-input cursor-pointer" 
                />
              </div>
            </div>
          </div>
        </SectionCard>

        <SectionCard 
          header={
            <div className="flex items-center justify-between">
              <h3 className="font-semibold text-foreground">Indicators of Compromise (Observables)</h3>
              <Button type="button" variant="secondary" onClick={handleAddObservable} className="h-8 text-xs py-1">
                <Plus className="w-3 h-3 mr-1" /> Add IOC
              </Button>
            </div>
          }
        >
          <div className="p-6">
            {observables.length === 0 ? (
              <p className="text-sm text-text-muted italic">No observables added. Click 'Add IOC' to include IPs, Domains, Hashes, etc.</p>
            ) : (
              <div className="space-y-4">
                {observables.map((obs, idx) => (
                  <div key={idx} className="flex flex-wrap items-center gap-3 bg-bg-darker p-3 rounded-xl border border-border-subtle">
                    <div className="w-32">
                      <CustomSelect
                        value={obs.type || "ip"}
                        onChange={(val) => handleObservableChange(idx, "type", val)}
                        options={[
                          { value: "ip", label: "IP Address" },
                          { value: "domain", label: "Domain" },
                          { value: "url", label: "URL" },
                          { value: "hash", label: "File Hash" },
                          { value: "email", label: "Email" },
                          { value: "other", label: "Other" },
                        ]}
                      />
                    </div>
                    <input 
                      required
                      type="text" 
                      value={obs.value || ""}
                      onChange={(e) => handleObservableChange(idx, "value", e.target.value)}
                      className="flex-1 bg-bg-card border border-border-subtle rounded-xl px-3 py-1.5 text-sm text-foreground focus:outline-none focus:border-accent-primary" 
                      placeholder="Value (e.g. 192.168.1.1)" 
                    />
                    <input 
                      type="text" 
                      value={obs.notes || ""}
                      onChange={(e) => handleObservableChange(idx, "notes", e.target.value)}
                      className="flex-1 bg-bg-card border border-border-subtle rounded-xl px-3 py-1.5 text-sm text-foreground focus:outline-none focus:border-accent-primary" 
                      placeholder="Context / Notes" 
                    />
                    <button type="button" onClick={() => handleRemoveObservable(idx)} className="text-text-muted hover:text-red-400 p-1">
                      <Trash2 className="w-4 h-4" />
                    </button>
                  </div>
                ))}
              </div>
            )}
          </div>
        </SectionCard>

        <div className="flex justify-end gap-3 pt-2">
          <Button type="button" variant="secondary" onClick={() => router.back()}>Cancel</Button>
          <Button type="submit" disabled={submitting}>
            {submitting ? (
              <><LoadingSpinner size="sm" className="mr-2" /> Submitting...</>
            ) : (
              <><Send className="w-4 h-4 mr-2" /> Submit Incident</>
            )}
          </Button>
        </div>
      </form>
    </div>
  );
}
