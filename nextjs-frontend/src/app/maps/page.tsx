"use client";

import { useEffect, useState, useCallback, useRef } from "react";
import { Network, Plus, Trash2, Save, X, Eye, EyeOff, LayoutDashboard, MonitorPlay, AlertTriangle, CheckCircle } from "lucide-react";

interface Device {
  id: number;
  name: string;
  ip: string;
  type: string;
  model: string;
  status: string;
  x: number;
  y: number;
  is_visible: boolean;
  icon_size: number;
}

interface Link {
  id: number;
  name: string;
  from_device_id: number;
  to_device_id: number;
  status: string;
}

interface Map {
  id: number;
  name: string;
}

export default function MapsPage() {
  const [maps, setMaps] = useState<Map[]>([]);
  const [currentMapId, setCurrentMapId] = useState<number>(0);
  const [devices, setDevices] = useState<Device[]>([]);
  const [links, setLinks] = useState<Link[]>([]);
  const [loading, setLoading] = useState(true);
  
  const [showAddModal, setShowAddModal] = useState(false);
  const [newMapName, setNewMapName] = useState('');
  
  const [showVisibilityModal, setShowVisibilityModal] = useState(false);
  
  const mapRef = useRef<HTMLDivElement>(null);
  
  const [draggingNode, setDraggingNode] = useState<number | null>(null);
  const [dragOffset, setDragOffset] = useState({ x: 0, y: 0 });

  const fetchData = useCallback(async (mapId?: number) => {
    setLoading(true);
    try {
      const url = mapId ? `/api/get_maps.php?map_id=${mapId}` : '/api/get_maps.php';
      const res = await fetch(url, { credentials: "include" });
      if (res.status === 401 || res.status === 403) window.location.href = '/home';
      const data = await res.json();
      setMaps(data.maps || []);
      setCurrentMapId(data.current_map_id || 0);
      setDevices(data.devices || []);
      setLinks(data.links || []);
    } catch (err) {
      console.error(err);
    }
    setLoading(false);
  }, []);

  useEffect(() => {
    fetchData();
  }, [fetchData]);

  const handleMapChange = (e: React.ChangeEvent<HTMLSelectElement>) => {
    const id = parseInt(e.target.value);
    fetchData(id);
  };

  const createMap = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!newMapName.trim()) return;
    try {
      const res = await fetch('/api/get_maps.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'create_map', map_name: newMapName }),
        credentials: "include"
      });
      const data = await res.json();
      if (data.success) {
        setShowAddModal(false);
        setNewMapName('');
        fetchData(data.map_id);
      }
    } catch (err) { console.error(err); }
  };

  const deleteMap = async () => {
    if (!confirm('Are you sure you want to delete this map?')) return;
    try {
      await fetch('/api/get_maps.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'delete_map', map_id: currentMapId }),
        credentials: "include"
      });
      fetchData();
    } catch (err) { console.error(err); }
  };

  const savePositions = async () => {
    const positions = devices.map(d => ({ device_id: d.id, x: d.x, y: d.y }));
    try {
      const res = await fetch('/api/get_maps.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'save_positions', map_id: currentMapId, positions }),
        credentials: "include"
      });
      const data = await res.json();
      if (data.success) alert('Positions saved successfully!');
    } catch (err) { console.error(err); }
  };

  const toggleVisibility = async (deviceId: number, currentVisible: boolean) => {
    try {
      await fetch('/api/get_maps.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'update_visibility', map_id: currentMapId, device_id: deviceId, is_visible: currentVisible ? 0 : 1 }),
        credentials: "include"
      });
      setDevices(prev => prev.map(d => d.id === deviceId ? { ...d, is_visible: !currentVisible } : d));
    } catch (err) { console.error(err); }
  };

  // --- Dragging Logic ---
  const handlePointerDown = (e: React.PointerEvent<HTMLDivElement>, id: number) => {
    e.stopPropagation();
    const node = e.currentTarget;
    const rect = node.getBoundingClientRect();
    const mapRect = mapRef.current?.getBoundingClientRect();
    if (!mapRect) return;
    
    setDraggingNode(id);
    setDragOffset({
      x: e.clientX - rect.left,
      y: e.clientY - rect.top
    });
    node.setPointerCapture(e.pointerId);
  };

  const handlePointerMove = (e: React.PointerEvent<HTMLDivElement>, id: number) => {
    if (draggingNode !== id) return;
    const mapRect = mapRef.current?.getBoundingClientRect();
    if (!mapRect) return;

    let newX = e.clientX - mapRect.left - dragOffset.x;
    let newY = e.clientY - mapRect.top - dragOffset.y;

    // Constrain to map
    newX = Math.max(0, Math.min(newX, mapRect.width - 60));
    newY = Math.max(0, Math.min(newY, mapRect.height - 60));

    setDevices(prev => prev.map(d => d.id === id ? { ...d, x: newX, y: newY } : d));
  };

  const handlePointerUp = (e: React.PointerEvent<HTMLDivElement>) => {
    e.currentTarget.releasePointerCapture(e.pointerId);
    setDraggingNode(null);
  };

  // --- Render Lines ---
  const visibleDevices = devices.filter(d => d.is_visible);
  
  const getDeviceCenter = (id: number) => {
    const d = visibleDevices.find(dev => dev.id === id);
    if (!d) return null;
    return { x: d.x + 30, y: d.y + 30 }; // assuming 60x60 node size
  };

  return (
    <div className="space-y-6 max-w-full mx-auto relative h-[calc(100vh-100px)] flex flex-col">
      <div className="flex flex-col md:flex-row justify-between items-start md:items-end gap-4 shrink-0">
        <div>
          <h1 className="text-2xl font-bold text-foreground tracking-wide flex items-center">
            <LayoutDashboard className="w-8 h-8 mr-3 text-accent-primary" /> Topology Maps
          </h1>
          <p className="text-text-muted mt-1 text-lg">Visual network overview and device relationships</p>
        </div>
        <div className="flex space-x-3 items-center">
          <select 
            value={currentMapId} 
            onChange={handleMapChange}
            className="bg-bg-card border border-border-subtle text-foreground rounded-2xl px-4 py-2 focus:outline-none focus:border-accent-primary min-w-[200px]"
          >
            {maps.length === 0 && <option value="0">No Maps Available</option>}
            {maps.map(m => (
              <option key={m.id} value={m.id}>{m.name}</option>
            ))}
          </select>

          <button onClick={() => setShowAddModal(true)} className="bg-bg-main hover:bg-bg-card border border-border-subtle text-accent-primary px-4 py-2 rounded-2xl font-bold flex items-center transition-colors">
            <Plus className="w-5 h-5" />
          </button>
          
          {currentMapId > 0 && (
            <>
              <button onClick={() => setShowVisibilityModal(true)} className="bg-blue-600 hover:bg-blue-500 text-foreground px-4 py-2 rounded-2xl font-bold flex items-center transition-colors">
                <Eye className="w-5 h-5 mr-2" /> Visibility
              </button>
              <button onClick={savePositions} className="bg-green-600 hover:bg-green-500 text-foreground px-4 py-2 rounded-2xl font-bold flex items-center transition-colors">
                <Save className="w-5 h-5 mr-2" /> Save
              </button>
              <button onClick={deleteMap} className="bg-red-600 hover:bg-red-500 text-foreground px-4 py-2 rounded-2xl font-bold flex items-center transition-colors">
                <Trash2 className="w-5 h-5" />
              </button>
            </>
          )}
        </div>
      </div>

      {currentMapId > 0 ? (
        <div className="bg-bg-darkest border border-border-subtle rounded-2xl flex-1 relative overflow-hidden" ref={mapRef}>
          {/* SVG for lines */}
          <svg className="absolute inset-0 w-full h-full pointer-events-none z-0">
            {links.map(link => {
              const from = getDeviceCenter(link.from_device_id);
              const to = getDeviceCenter(link.to_device_id);
              if (!from || !to) return null;
              
              const isUp = link.status === 'up';
              const color = isUp ? '#22c55e' : '#ef4444';
              
              return (
                <line 
                  key={link.id} 
                  x1={from.x} y1={from.y} 
                  x2={to.x} y2={to.y} 
                  stroke={color} 
                  strokeWidth="3" 
                  className={isUp ? 'opacity-50' : 'opacity-100 animate-pulse'}
                />
              );
            })}
          </svg>

          {/* Device Nodes */}
          {visibleDevices.map(device => {
            const isUp = device.status === 'up';
            const colorClass = isUp ? 'bg-green-500' : 'bg-red-500 animate-pulse';
            
            return (
              <div 
                key={device.id}
                className="absolute z-10 w-[60px] cursor-pointer flex flex-col items-center justify-center transform transition-transform duration-100"
                style={{ left: device.x, top: device.y, touchAction: 'none' }}
                onPointerDown={(e) => handlePointerDown(e, device.id)}
                onPointerMove={(e) => handlePointerMove(e, device.id)}
                onPointerUp={handlePointerUp}
                onPointerCancel={handlePointerUp}
              >
                <div className={`relative w-12 h-12 bg-bg-card rounded-2xl border-2 ${isUp ? 'border-green-500/50' : 'border-red-500'} flex items-center justify-center`}>
                  <MonitorPlay className={`w-6 h-6 ${isUp ? 'text-text-muted' : 'text-red-400'}`} />
                  <span className={`absolute -bottom-1 -right-1 w-4 h-4 rounded-full border-2 border-bg-darkest ${colorClass}`}></span>
                </div>
                <div className="mt-1 bg-black/60 px-2 py-0.5 rounded-2xl text-[10px] text-foreground font-bold max-w-[80px] truncate border border-border-subtle backdrop-">
                  {device.name}
                </div>
              </div>
            );
          })}
        </div>
      ) : (
        <div className="flex-1 flex flex-col items-center justify-center border-2 border-dashed border-border-subtle rounded-2xl bg-bg-main">
          <Network className="w-16 h-16 text-text-muted mb-4 opacity-50" />
          <h3 className="text-xl font-bold text-foreground mb-2">No Maps Found</h3>
          <p className="text-text-muted mb-4">Create your first network topology map to visualize devices.</p>
          <button onClick={() => setShowAddModal(true)} className="bg-accent-primary hover:bg-accent-hover text-foreground px-6 py-2 rounded-2xl font-bold flex items-center transition-colors">
            <Plus className="w-5 h-5 mr-2" /> Create Map
          </button>
        </div>
      )}

      {/* Add Map Modal */}
      {showAddModal && (
        <div className="fixed inset-0 bg-black/70 backdrop- z-50 flex items-center justify-center p-4">
          <div className="bg-bg-main border border-border-subtle rounded-2xl w-full max-w-md">
            <div className="bg-bg-raised p-4 border-b border-border-subtle flex justify-between items-center">
              <h3 className="text-xl font-bold text-foreground">Create New Map</h3>
              <button onClick={() => setShowAddModal(false)} className="text-text-muted hover:text-foreground"><X className="w-6 h-6"/></button>
            </div>
            <form onSubmit={createMap} className="p-6">
              <label className="block text-sm font-bold text-text-muted mb-1">Map Name *</label>
              <input type="text" required value={newMapName} onChange={e => setNewMapName(e.target.value)} className="w-full bg-bg-card border border-border-subtle text-foreground rounded-2xl px-3 py-2 focus:outline-none focus:border-accent-primary" autoFocus />
              <div className="pt-6 flex justify-end space-x-3 mt-2 border-t border-border-subtle">
                <button type="button" onClick={() => setShowAddModal(false)} className="px-4 py-2 bg-bg-card text-foreground hover:bg-border-subtle rounded-2xl font-bold transition-colors">Cancel</button>
                <button type="submit" className="px-6 py-2 bg-accent-primary hover:bg-accent-hover text-foreground rounded-2xl font-bold transition-colors">Create</button>
              </div>
            </form>
          </div>
        </div>
      )}

      {/* Visibility Modal */}
      {showVisibilityModal && (
        <div className="fixed inset-0 bg-black/70 backdrop- z-50 flex items-center justify-center p-4">
          <div className="bg-bg-main border border-border-subtle rounded-2xl w-full max-w-2xl h-[70vh] flex flex-col">
            <div className="bg-bg-raised p-4 border-b border-border-subtle flex justify-between items-center shrink-0">
              <h3 className="text-xl font-bold text-foreground flex items-center"><Eye className="w-5 h-5 mr-2 text-accent-primary"/> Device Visibility</h3>
              <button onClick={() => setShowVisibilityModal(false)} className="text-text-muted hover:text-foreground"><X className="w-6 h-6"/></button>
            </div>
            <div className="flex-1 overflow-auto p-4 custom-scrollbar">
              <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                {devices.map(d => (
                  <div key={d.id} className="bg-bg-card border border-border-subtle rounded-2xl p-3 flex justify-between items-center">
                    <div>
                      <div className="text-foreground font-bold">{d.name}</div>
                      <div className="text-xs text-text-muted">{d.ip} ({d.type})</div>
                    </div>
                    <button 
                      onClick={() => toggleVisibility(d.id, d.is_visible)}
                      className={`p-2 rounded-2xl transition-colors border ${d.is_visible ? 'bg-green-900/30 text-green-400 border-green-500/50 hover:bg-green-800/50' : 'bg-gray-800 text-gray-500 border-gray-700 hover:bg-gray-700'}`}
                    >
                      {d.is_visible ? <Eye className="w-5 h-5" /> : <EyeOff className="w-5 h-5" />}
                    </button>
                  </div>
                ))}
              </div>
            </div>
            <div className="p-4 border-t border-border-subtle flex justify-end bg-bg-raised shrink-0">
              <button onClick={() => setShowVisibilityModal(false)} className="px-6 py-2 bg-blue-600 hover:bg-blue-500 text-foreground rounded-2xl font-bold transition-colors">Done</button>
            </div>
          </div>
        </div>
      )}

    </div>
  );
}
