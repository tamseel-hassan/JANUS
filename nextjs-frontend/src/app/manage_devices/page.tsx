"use client";

import { useEffect, useState, useCallback } from "react";
import { Network, Server, Shield, Wifi, HardDrive, Cpu, Plus, Edit, Trash2, Link as LinkIcon, Save, X, Search, MapPin, Phone, Mail, Box, ShieldCheck, ShieldAlert } from "lucide-react";

interface Device {
  id: number;
  name: string;
  type: string;
  ip: string;
  model: string;
  city: string;
  sub_office: string;
  country: string;
  contact_number: string;
  email: string;
  snmp_community: string;
  snmp_version: string;
  snmp_port: number;
}

interface Link {
  id: number;
  name: string;
  ip: string;
  from_device_id: number;
  to_device_id: number;
  from_name?: string;
  to_name?: string;
}

export default function ManageDevicesPage() {
  const [devices, setDevices] = useState<Device[]>([]);
  const [links, setLinks] = useState<Link[]>([]);
  const [models, setModels] = useState<Record<string, string[]>>({});
  const [loading, setLoading] = useState(true);
  const [notification, setNotification] = useState<{type: 'success' | 'error', message: string} | null>(null);

  const [activeTab, setActiveTab] = useState<'devices' | 'links'>('devices');
  const [search, setSearch] = useState('');

  // Modals
  const [showDeviceModal, setShowDeviceModal] = useState(false);
  const [showLinkModal, setShowLinkModal] = useState(false);
  
  // Forms
  const [editingDevice, setEditingDevice] = useState<Device | null>(null);
  const [editingLink, setEditingLink] = useState<Link | null>(null);

  const emptyDevice: Device = { id: 0, name: '', type: 'server', ip: '', model: '', city: '', sub_office: '', country: '', contact_number: '', email: '', snmp_community: '', snmp_version: '2c', snmp_port: 161 };
  const emptyLink: Link = { id: 0, name: '', ip: '', from_device_id: 0, to_device_id: 0 };
  
  const [deviceForm, setDeviceForm] = useState<Device>(emptyDevice);
  const [customModel, setCustomModel] = useState('');
  
  const [linkForm, setLinkForm] = useState<Link>(emptyLink);

  const fetchData = useCallback(async () => {
    setLoading(true);
    try {
      const res = await fetch('/api/get_devices.php', { credentials: "include" });
      if (res.status === 401 || res.status === 403) window.location.href = '/home';
      const data = await res.json();
      setDevices(data.devices || []);
      setLinks(data.links || []);
      setModels(data.models || {});
    } catch (err) {
      console.error(err);
    }
    setLoading(false);
  }, []);

  useEffect(() => {
    fetchData();
  }, [fetchData]);

  const showNotification = (type: 'success' | 'error', message: string) => {
    setNotification({ type, message });
    setTimeout(() => setNotification(null), 5000);
  };

  const handleDeviceAction = async (e: React.FormEvent) => {
    e.preventDefault();
    const action = editingDevice ? 'edit_device' : 'add_device';
    const payload = { action, ...deviceForm, custom_model: customModel };
    
    try {
      const res = await fetch('/api/get_devices.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload),
        credentials: "include"
      });
      const data = await res.json();
      if (data.success) {
        showNotification('success', data.success);
        setShowDeviceModal(false);
        fetchData();
      } else {
        showNotification('error', data.error);
      }
    } catch (err: any) {
      showNotification('error', err.message);
    }
  };

  const handleDeleteDevice = async (id: number, name: string) => {
    if (!confirm(`Are you sure you want to delete ${name}? This will also delete all associated logs and metrics.`)) return;
    try {
      const res = await fetch('/api/get_devices.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'delete_device', id }),
        credentials: "include"
      });
      const data = await res.json();
      if (data.success) {
        showNotification('success', data.success);
        fetchData();
      } else showNotification('error', data.error);
    } catch (err: any) {
      showNotification('error', err.message);
    }
  };

  const handleLinkAction = async (e: React.FormEvent) => {
    e.preventDefault();
    const action = editingLink ? 'edit_link' : 'add_link';
    const payload = { action, ...linkForm };
    try {
      const res = await fetch('/api/get_devices.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload),
        credentials: "include"
      });
      const data = await res.json();
      if (data.success) {
        showNotification('success', data.success);
        setShowLinkModal(false);
        fetchData();
      } else {
        showNotification('error', data.error);
      }
    } catch (err: any) {
      showNotification('error', err.message);
    }
  };

  const handleDeleteLink = async (id: number, name: string) => {
    if (!confirm(`Are you sure you want to delete link ${name}?`)) return;
    try {
      const res = await fetch('/api/get_devices.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'delete_link', id }),
        credentials: "include"
      });
      const data = await res.json();
      if (data.success) {
        showNotification('success', data.success);
        fetchData();
      } else showNotification('error', data.error);
    } catch (err: any) {
      showNotification('error', err.message);
    }
  };

  const getDeviceIcon = (type: string) => {
    switch (type.toLowerCase()) {
      case 'server': return <Server className="w-4 h-4 text-blue-400 mr-2 inline" />;
      case 'switch': return <Network className="w-4 h-4 text-purple-400 mr-2 inline" />;
      case 'router': return <Box className="w-4 h-4 text-green-400 mr-2 inline" />;
      case 'firewall': return <Shield className="w-4 h-4 text-red-400 mr-2 inline" />;
      case 'wireless': return <Wifi className="w-4 h-4 text-yellow-400 mr-2 inline" />;
      case 'storage': return <HardDrive className="w-4 h-4 text-orange-400 mr-2 inline" />;
      case 'iot': return <Cpu className="w-4 h-4 text-cyan-400 mr-2 inline" />;
      default: return <Server className="w-4 h-4 text-gray-400 mr-2 inline" />;
    }
  };

  const typeCounts = devices.reduce((acc, dev) => {
    acc[dev.type] = (acc[dev.type] || 0) + 1;
    return acc;
  }, {} as Record<string, number>);

  const filteredDevices = devices.filter(d => 
    d.name.toLowerCase().includes(search.toLowerCase()) || 
    d.ip.includes(search) ||
    d.type.toLowerCase().includes(search.toLowerCase())
  );
  const filteredLinks = links.filter(l => 
    l.name.toLowerCase().includes(search.toLowerCase()) || 
    l.ip.includes(search)
  );

  return (
    <div className="space-y-6 max-w-7xl mx-auto relative pb-10">
      
      {/* Notification */}
      {notification && (
        <div className={`fixed top-4 right-4 z-50 p-4 rounded-2xl border  flex items-center transform transition-all ${
          notification.type === 'success' ? 'bg-green-900/90 border-green-500 text-green-100' : 'bg-red-900/90 border-red-500 text-red-100'
        }`}>
          {notification.type === 'success' ? <ShieldCheck className="w-5 h-5 mr-3" /> : <ShieldAlert className="w-5 h-5 mr-3" />}
          {notification.message}
        </div>
      )}

      <div className="flex flex-col md:flex-row justify-between items-start md:items-end gap-4">
        <div>
          <h1 className="text-2xl font-bold text-foreground tracking-wide flex items-center">
            <Network className="w-8 h-8 mr-3 text-accent-primary" /> Network Infrastructure
          </h1>
          <p className="text-text-muted mt-1 text-lg">Manage all network devices, appliances, and interconnections</p>
        </div>
        <div className="flex space-x-3">
          <button onClick={() => { setEditingDevice(null); setDeviceForm(emptyDevice); setCustomModel(''); setShowDeviceModal(true); }} className="bg-accent-primary hover:bg-accent-hover text-foreground px-4 py-2 rounded-2xl font-bold flex items-center transition-colors">
            <Plus className="w-5 h-5 mr-2" /> Add Device
          </button>
          <button onClick={() => { setEditingLink(null); setLinkForm(emptyLink); setShowLinkModal(true); }} className="bg-blue-600 hover:bg-blue-500 text-foreground px-4 py-2 rounded-2xl font-bold flex items-center transition-colors">
            <LinkIcon className="w-5 h-5 mr-2" /> Add Link
          </button>
        </div>
      </div>

      <div className="grid grid-cols-1 md:grid-cols-3 gap-6">
        <div className="bg-bg-raised border border-border-subtle rounded-2xl p-6 flex flex-col items-center justify-center">
          <h6 className="text-text-muted text-sm font-bold uppercase tracking-wider mb-2">Total Devices</h6>
          <div className="text-2xl font-black text-foreground">{devices.length}</div>
        </div>
        <div className="bg-bg-raised border border-border-subtle rounded-2xl p-6 flex flex-col items-center justify-center">
          <h6 className="text-text-muted text-sm font-bold uppercase tracking-wider mb-2">Device Types</h6>
          <div className="text-2xl font-black text-accent-primary">{Object.keys(typeCounts).length}</div>
        </div>
        <div className="bg-bg-raised border border-border-subtle rounded-2xl p-6 flex flex-col items-center justify-center">
          <h6 className="text-text-muted text-sm font-bold uppercase tracking-wider mb-2">Network Links</h6>
          <div className="text-2xl font-black text-blue-400">{links.length}</div>
        </div>
      </div>

      {/* Tabs */}
      <div className="flex border-b border-border-subtle">
        <button onClick={() => setActiveTab('devices')} className={`px-6 py-3 font-bold text-sm tracking-wide ${activeTab === 'devices' ? 'text-accent-primary border-b-2 border-accent-primary' : 'text-text-muted hover:text-foreground'}`}>
          DEVICES
        </button>
        <button onClick={() => setActiveTab('links')} className={`px-6 py-3 font-bold text-sm tracking-wide ${activeTab === 'links' ? 'text-accent-primary border-b-2 border-accent-primary' : 'text-text-muted hover:text-foreground'}`}>
          LINKS
        </button>
      </div>

      <div className="bg-bg-raised border border-border-subtle rounded-2xl overflow-hidden flex flex-col">
        <div className="p-4 border-b border-border-subtle bg-bg-main flex gap-4 items-center">
          <div className="relative flex-1 max-w-md">
            <Search className="w-4 h-4 absolute left-3 top-1/2 -translate-y-1/2 text-text-muted" />
            <input 
              type="text" placeholder={`Search ${activeTab}...`} value={search} onChange={(e) => setSearch(e.target.value)}
              className="w-full bg-bg-card border border-border-subtle rounded-2xl py-2 pl-10 pr-3 text-foreground focus:outline-none focus:border-accent-primary" 
            />
          </div>
        </div>

        <div className="flex-1 overflow-auto custom-scrollbar h-[500px]">
          {activeTab === 'devices' && (
            <table className="w-full text-left text-sm whitespace-nowrap">
              <thead className="bg-bg-main text-text-muted uppercase text-xs tracking-wider border-b border-border-subtle sticky top-0 z-10">
                <tr>
                  <th className="px-6 py-4 font-bold">Device Name</th>
                  <th className="px-6 py-4 font-bold">IP Address</th>
                  <th className="px-6 py-4 font-bold">Type</th>
                  <th className="px-6 py-4 font-bold">Model</th>
                  <th className="px-6 py-4 font-bold">Location</th>
                  <th className="px-6 py-4 font-bold text-right">Actions</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-border-subtle">
                {loading && <tr className="animate-pulse"><td colSpan={6} className="px-6 py-10 bg-bg-card/20"></td></tr>}
                {filteredDevices.map(d => (
                  <tr key={d.id} className="hover:bg-bg-card/50 transition-colors">
                    <td className="px-6 py-3 font-bold text-foreground">{d.name}</td>
                    <td className="px-6 py-3 font-mono text-xs text-text-muted">{d.ip}</td>
                    <td className="px-6 py-3 capitalize">{getDeviceIcon(d.type)} {d.type}</td>
                    <td className="px-6 py-3 text-[#d8cce2]">{d.model}</td>
                    <td className="px-6 py-3">
                      <div className="flex flex-col">
                        <span className="text-text-muted text-xs"><MapPin className="w-3 h-3 inline mr-1"/>{d.city || 'N/A'}, {d.country}</span>
                      </div>
                    </td>
                    <td className="px-6 py-3 text-right">
                      <button onClick={() => { setEditingDevice(d); setDeviceForm(d); setCustomModel(''); setShowDeviceModal(true); }} className="p-1.5 bg-bg-card hover:bg-blue-600 text-blue-400 hover:text-foreground rounded-2xl border border-border-subtle hover:border-blue-500 mr-2" title="Edit">
                        <Edit className="w-4 h-4" />
                      </button>
                      <button onClick={() => handleDeleteDevice(d.id, d.name)} className="p-1.5 bg-bg-card hover:bg-red-600 text-red-400 hover:text-foreground rounded-2xl border border-border-subtle hover:border-red-500" title="Delete">
                        <Trash2 className="w-4 h-4" />
                      </button>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          )}
          
          {activeTab === 'links' && (
            <table className="w-full text-left text-sm whitespace-nowrap">
              <thead className="bg-bg-main text-text-muted uppercase text-xs tracking-wider border-b border-border-subtle sticky top-0 z-10">
                <tr>
                  <th className="px-6 py-4 font-bold">Link Name</th>
                  <th className="px-6 py-4 font-bold">IP/Subnet</th>
                  <th className="px-6 py-4 font-bold">From Device</th>
                  <th className="px-6 py-4 font-bold">To Device</th>
                  <th className="px-6 py-4 font-bold text-right">Actions</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-border-subtle">
                {loading && <tr className="animate-pulse"><td colSpan={5} className="px-6 py-10 bg-bg-card/20"></td></tr>}
                {filteredLinks.map(l => (
                  <tr key={l.id} className="hover:bg-bg-card/50 transition-colors">
                    <td className="px-6 py-4 font-bold text-foreground">{l.name}</td>
                    <td className="px-6 py-4 font-mono text-xs text-text-muted">{l.ip}</td>
                    <td className="px-6 py-4 text-blue-300 font-semibold">{l.from_name}</td>
                    <td className="px-6 py-4 text-purple-300 font-semibold">{l.to_name}</td>
                    <td className="px-6 py-4 text-right">
                      <button onClick={() => { setEditingLink(l); setLinkForm(l); setShowLinkModal(true); }} className="p-1.5 bg-bg-card hover:bg-blue-600 text-blue-400 hover:text-foreground rounded-2xl border border-border-subtle hover:border-blue-500 mr-2" title="Edit">
                        <Edit className="w-4 h-4" />
                      </button>
                      <button onClick={() => handleDeleteLink(l.id, l.name)} className="p-1.5 bg-bg-card hover:bg-red-600 text-red-400 hover:text-foreground rounded-2xl border border-border-subtle hover:border-red-500" title="Delete">
                        <Trash2 className="w-4 h-4" />
                      </button>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          )}
        </div>
      </div>

      {/* Device Modal */}
      {showDeviceModal && (
        <div className="fixed inset-0 bg-black/70 backdrop- z-50 flex items-center justify-center p-4 overflow-auto">
          <div className="bg-bg-main border border-border-subtle rounded-2xl w-full max-w-3xl my-8 relative">
            <div className="bg-bg-raised p-4 border-b border-border-subtle flex justify-between items-center sticky top-0 z-10">
              <h3 className="text-xl font-bold text-foreground flex items-center">
                {editingDevice ? <Edit className="w-5 h-5 mr-2 text-blue-400" /> : <Plus className="w-5 h-5 mr-2 text-accent-primary" />} 
                {editingDevice ? 'Edit Device' : 'Add New Device'}
              </h3>
              <button onClick={() => setShowDeviceModal(false)} className="text-text-muted hover:text-foreground"><X className="w-6 h-6"/></button>
            </div>
            <form onSubmit={handleDeviceAction} className="p-6">
              <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
                {/* General Info */}
                <div className="space-y-4">
                  <h4 className="text-accent-primary font-bold border-b border-border-subtle pb-2">General Info</h4>
                  <div>
                    <label className="block text-xs font-bold text-text-muted mb-1">Device Name *</label>
                    <input type="text" required value={deviceForm.name} onChange={e => setDeviceForm({...deviceForm, name: e.target.value})} className="w-full bg-bg-card border border-border-subtle text-foreground rounded-2xl px-3 py-2 text-sm focus:outline-none focus:border-accent-primary" />
                  </div>
                  <div>
                    <label className="block text-xs font-bold text-text-muted mb-1">IP Address *</label>
                    <input type="text" required value={deviceForm.ip} onChange={e => setDeviceForm({...deviceForm, ip: e.target.value})} className="w-full bg-bg-card border border-border-subtle text-foreground rounded-2xl px-3 py-2 text-sm focus:outline-none focus:border-accent-primary" placeholder="192.168.1.1" />
                  </div>
                  <div>
                    <label className="block text-xs font-bold text-text-muted mb-1">Type *</label>
                    <select required value={deviceForm.type} onChange={e => { setDeviceForm({...deviceForm, type: e.target.value, model: ''}); setCustomModel(''); }} className="w-full bg-bg-card border border-border-subtle text-foreground rounded-2xl px-3 py-2 text-sm focus:outline-none focus:border-accent-primary">
                      <option value="server">Server</option>
                      <option value="switch">Switch</option>
                      <option value="firewall">Firewall</option>
                      <option value="router">Router</option>
                      <option value="wireless">Wireless AP</option>
                      <option value="loadbalancer">Load Balancer</option>
                      <option value="storage">Storage/NAS</option>
                      <option value="iot">IoT/Other</option>
                    </select>
                  </div>
                  <div>
                    <label className="block text-xs font-bold text-text-muted mb-1">Model *</label>
                    <select required value={deviceForm.model} onChange={e => setDeviceForm({...deviceForm, model: e.target.value})} className="w-full bg-bg-card border border-border-subtle text-foreground rounded-2xl px-3 py-2 text-sm focus:outline-none focus:border-accent-primary mb-2">
                      <option value="">Select a model</option>
                      {(models[deviceForm.type] || models['others']).map(m => (
                        <option key={m} value={m}>{m}</option>
                      ))}
                    </select>
                    {deviceForm.model === 'Other' && (
                      <input type="text" placeholder="Enter custom model..." required value={customModel} onChange={e => setCustomModel(e.target.value)} className="w-full bg-bg-card border border-border-subtle text-foreground rounded-2xl px-3 py-2 text-sm focus:outline-none focus:border-accent-primary" />
                    )}
                  </div>
                </div>

                {/* Location & Contact */}
                <div className="space-y-4">
                  <h4 className="text-accent-primary font-bold border-b border-border-subtle pb-2">Location & Contact</h4>
                  <div className="flex gap-4">
                    <div className="flex-1">
                      <label className="block text-xs font-bold text-text-muted mb-1">City</label>
                      <input type="text" value={deviceForm.city} onChange={e => setDeviceForm({...deviceForm, city: e.target.value})} className="w-full bg-bg-card border border-border-subtle text-foreground rounded-2xl px-3 py-2 text-sm focus:outline-none focus:border-accent-primary" />
                    </div>
                    <div className="flex-1">
                      <label className="block text-xs font-bold text-text-muted mb-1">Country</label>
                      <input type="text" value={deviceForm.country} onChange={e => setDeviceForm({...deviceForm, country: e.target.value})} className="w-full bg-bg-card border border-border-subtle text-foreground rounded-2xl px-3 py-2 text-sm focus:outline-none focus:border-accent-primary" />
                    </div>
                  </div>
                  <div>
                    <label className="block text-xs font-bold text-text-muted mb-1">Sub Office</label>
                    <input type="text" value={deviceForm.sub_office} onChange={e => setDeviceForm({...deviceForm, sub_office: e.target.value})} className="w-full bg-bg-card border border-border-subtle text-foreground rounded-2xl px-3 py-2 text-sm focus:outline-none focus:border-accent-primary" />
                  </div>
                  <div>
                    <label className="block text-xs font-bold text-text-muted mb-1">Contact Number</label>
                    <input type="text" value={deviceForm.contact_number} onChange={e => setDeviceForm({...deviceForm, contact_number: e.target.value})} className="w-full bg-bg-card border border-border-subtle text-foreground rounded-2xl px-3 py-2 text-sm focus:outline-none focus:border-accent-primary" />
                  </div>
                  <div>
                    <label className="block text-xs font-bold text-text-muted mb-1">Email</label>
                    <input type="email" value={deviceForm.email} onChange={e => setDeviceForm({...deviceForm, email: e.target.value})} className="w-full bg-bg-card border border-border-subtle text-foreground rounded-2xl px-3 py-2 text-sm focus:outline-none focus:border-accent-primary" />
                  </div>
                </div>

                {/* SNMP configuration */}
                <div className="space-y-4 md:col-span-2">
                  <h4 className="text-accent-primary font-bold border-b border-border-subtle pb-2">SNMP Configuration</h4>
                  <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
                    <div>
                      <label className="block text-xs font-bold text-text-muted mb-1">SNMP Community</label>
                      <input type="text" value={deviceForm.snmp_community} onChange={e => setDeviceForm({...deviceForm, snmp_community: e.target.value})} className="w-full bg-bg-card border border-border-subtle text-foreground rounded-2xl px-3 py-2 text-sm focus:outline-none focus:border-accent-primary" placeholder="public" />
                    </div>
                    <div>
                      <label className="block text-xs font-bold text-text-muted mb-1">SNMP Version</label>
                      <select value={deviceForm.snmp_version} onChange={e => setDeviceForm({...deviceForm, snmp_version: e.target.value})} className="w-full bg-bg-card border border-border-subtle text-foreground rounded-2xl px-3 py-2 text-sm focus:outline-none focus:border-accent-primary">
                        <option value="1">v1</option>
                        <option value="2c">v2c</option>
                        <option value="3">v3</option>
                      </select>
                    </div>
                    <div>
                      <label className="block text-xs font-bold text-text-muted mb-1">SNMP Port</label>
                      <input type="number" value={deviceForm.snmp_port} onChange={e => setDeviceForm({...deviceForm, snmp_port: parseInt(e.target.value) || 161})} className="w-full bg-bg-card border border-border-subtle text-foreground rounded-2xl px-3 py-2 text-sm focus:outline-none focus:border-accent-primary" />
                    </div>
                  </div>
                </div>
              </div>

              <div className="pt-6 flex justify-end space-x-3 mt-6 border-t border-border-subtle">
                <button type="button" onClick={() => setShowDeviceModal(false)} className="px-4 py-2 bg-bg-card text-foreground hover:bg-border-subtle rounded-2xl font-bold transition-colors">Cancel</button>
                <button type="submit" className="px-6 py-2 bg-accent-primary hover:bg-accent-hover text-foreground rounded-2xl font-bold transition-colors flex items-center">
                  <Save className="w-4 h-4 mr-2" /> Save Device
                </button>
              </div>
            </form>
          </div>
        </div>
      )}

      {/* Link Modal */}
      {showLinkModal && (
        <div className="fixed inset-0 bg-black/70 backdrop- z-50 flex items-center justify-center p-4">
          <div className="bg-bg-main border border-border-subtle rounded-2xl w-full max-w-md">
            <div className="bg-bg-raised p-4 border-b border-border-subtle flex justify-between items-center">
              <h3 className="text-xl font-bold text-foreground flex items-center">
                {editingLink ? <Edit className="w-5 h-5 mr-2 text-blue-400" /> : <LinkIcon className="w-5 h-5 mr-2 text-blue-400" />} 
                {editingLink ? 'Edit Link' : 'Add New Link'}
              </h3>
              <button onClick={() => setShowLinkModal(false)} className="text-text-muted hover:text-foreground"><X className="w-6 h-6"/></button>
            </div>
            <form onSubmit={handleLinkAction} className="p-6 space-y-4">
              <div>
                <label className="block text-xs font-bold text-text-muted mb-1">Link Name *</label>
                <input type="text" required value={linkForm.name} onChange={e => setLinkForm({...linkForm, name: e.target.value})} className="w-full bg-bg-card border border-border-subtle text-foreground rounded-2xl px-3 py-2 text-sm focus:outline-none focus:border-blue-500" placeholder="e.g. Core Switch to FW1" />
              </div>
              <div>
                <label className="block text-xs font-bold text-text-muted mb-1">IP/Subnet *</label>
                <input type="text" required value={linkForm.ip} onChange={e => setLinkForm({...linkForm, ip: e.target.value})} className="w-full bg-bg-card border border-border-subtle text-foreground rounded-2xl px-3 py-2 text-sm focus:outline-none focus:border-blue-500" placeholder="10.0.0.0/24 or IP" />
              </div>
              <div>
                <label className="block text-xs font-bold text-text-muted mb-1">From Device *</label>
                <select required value={linkForm.from_device_id || ''} onChange={e => setLinkForm({...linkForm, from_device_id: parseInt(e.target.value)})} className="w-full bg-bg-card border border-border-subtle text-foreground rounded-2xl px-3 py-2 text-sm focus:outline-none focus:border-blue-500">
                  <option value="" disabled>Select from device...</option>
                  {devices.map(d => <option key={d.id} value={d.id}>{d.name} ({d.ip})</option>)}
                </select>
              </div>
              <div>
                <label className="block text-xs font-bold text-text-muted mb-1">To Device *</label>
                <select required value={linkForm.to_device_id || ''} onChange={e => setLinkForm({...linkForm, to_device_id: parseInt(e.target.value)})} className="w-full bg-bg-card border border-border-subtle text-foreground rounded-2xl px-3 py-2 text-sm focus:outline-none focus:border-blue-500">
                  <option value="" disabled>Select to device...</option>
                  {devices.map(d => <option key={d.id} value={d.id}>{d.name} ({d.ip})</option>)}
                </select>
              </div>
              <div className="pt-4 flex justify-end space-x-3">
                <button type="button" onClick={() => setShowLinkModal(false)} className="px-4 py-2 bg-bg-card text-foreground hover:bg-border-subtle rounded-2xl font-bold transition-colors">Cancel</button>
                <button type="submit" className="px-6 py-2 bg-blue-600 hover:bg-blue-500 text-foreground rounded-2xl font-bold transition-colors flex items-center">
                  <Save className="w-4 h-4 mr-2" /> Save Link
                </button>
              </div>
            </form>
          </div>
        </div>
      )}

    </div>
  );
}
