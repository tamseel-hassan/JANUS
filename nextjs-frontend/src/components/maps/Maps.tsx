"use client";

import { useEffect, useState, useCallback, useRef } from "react";
import { 
  Network, Plus, Trash2, Save, Eye, LayoutDashboard
} from "lucide-react";
import { confirmDialog } from "@/lib/use-confirm";
import { toast } from "sonner";
import CustomSelect from "@/components/ui/CustomSelect";
import { Button } from "@/components/ui/Button";
import { Modal } from "@/components/ui/Modal";
import { Device, Link, Map } from "@/types/maps";
import { DeviceNode } from "@/components/maps/DeviceNode";
import { VisibilityModal } from "@/components/maps/VisibilityModal";
import { safeFetch } from "@/lib/safeFetch";

export function Maps() {
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
    const url = mapId ? `/api/get_maps.php?map_id=${mapId}` : '/api/get_maps.php';
    const data = await safeFetch<{ maps: Map[]; current_map_id: number; devices: Device[]; links: Link[] }>(
      url, {}, "Maps"
    );
    if (data) {
      setMaps(data.maps || []);
      setCurrentMapId(data.current_map_id || 0);
      setDevices(data.devices || []);
      setLinks(data.links || []);
    }
    setLoading(false);
  }, []);

  useEffect(() => {
    fetchData();
  }, [fetchData]);

  const handleMapChange = (val: string) => {
    const id = parseInt(val);
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
    const ok = await confirmDialog({
      title: "Delete Map",
      description: "Are you sure you want to delete this map?",
      variant: "destructive",
      confirmText: "Delete"
    });
    if (!ok) return;
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
      if (data.success) toast.success('Positions saved successfully!');
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
    newX = Math.max(0, Math.min(newX, mapRect.width - 80));
    newY = Math.max(0, Math.min(newY, mapRect.height - 80));

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
    // Align lines with center of 48px (w-12) icon within the 80px (w-20) parent container
    return { x: d.x + 40, y: d.y + 24 };
  };

  if (loading) {
    return (
      <div className="flex justify-center items-center h-64">
        <div className="w-10 h-10 border-4 border-border-subtle border-t-accent-primary rounded-full animate-spin"></div>
      </div>
    );
  }

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
          <CustomSelect
            value={currentMapId.toString()}
            onChange={handleMapChange}
            options={
              maps.length === 0 
                ? [{ value: "0", label: "No Maps Available" }] 
                : maps.map(m => ({ value: m.id.toString(), label: m.name }))
            }
            className="min-w-[200px]"
          />

          <Button 
            variant="outline-primary"
            size="icon"
            onClick={() => setShowAddModal(true)} 
            title="Create New Map"
          >
            <Plus className="w-5 h-5" />
          </Button>
          
          {currentMapId > 0 && (
            <>
              <Button 
                variant="outline"
                className="text-blue-400 border-blue-500/30 hover:bg-blue-500/10 hover:border-blue-500/60"
                onClick={() => setShowVisibilityModal(true)} 
              >
                <Eye className="w-4 h-4 mr-2" /> Visibility
              </Button>
              <Button 
                variant="outline-success"
                onClick={savePositions} 
              >
                <Save className="w-4 h-4 mr-2" /> Save
              </Button>
              <Button 
                variant="outline-danger"
                size="icon"
                onClick={deleteMap} 
                title="Delete Map"
              >
                <Trash2 className="w-4 h-4" />
              </Button>
            </>
          )}
        </div>
      </div>

      {currentMapId > 0 ? (
        <div 
          className="bg-bg-darkest border border-border-subtle rounded-2xl flex-1 relative overflow-hidden shadow-inner" 
          ref={mapRef}
          style={{
            backgroundImage: 'radial-gradient(rgba(198, 102, 244, 0.08) 1px, transparent 1px)',
            backgroundSize: '24px 24px'
          }}
        >
          {/* SVG for lines */}
          <svg className="absolute inset-0 w-full h-full pointer-events-none z-0">
            {links.map(link => {
              const from = getDeviceCenter(link.from_device_id);
              const to = getDeviceCenter(link.to_device_id);
              if (!from || !to) return null;
              
              const isUp = link.status === 'up';
              const color = isUp ? 'rgba(34, 197, 94, 0.4)' : 'rgba(239, 68, 68, 0.8)';
              
              return (
                <line 
                  key={link.id} 
                  x1={from.x} y1={from.y} 
                  x2={to.x} y2={to.y} 
                  stroke={color} 
                  strokeWidth="2.5" 
                  className={isUp ? 'opacity-80' : 'opacity-100 animate-pulse'}
                  strokeDasharray={isUp ? 'none' : '6, 6'}
                />
              );
            })}
          </svg>

          {/* Device Nodes */}
          {visibleDevices.map(device => (
            <DeviceNode
              key={device.id}
              device={device}
              draggingNode={draggingNode}
              handlePointerDown={handlePointerDown}
              handlePointerMove={handlePointerMove}
              handlePointerUp={handlePointerUp}
            />
          ))}
        </div>
      ) : (
        <div className="flex-1 flex flex-col items-center justify-center border-2 border-dashed border-border-subtle rounded-2xl bg-bg-main">
          <Network className="w-16 h-16 text-text-muted mb-4 opacity-50" />
          <h3 className="text-xl font-bold text-foreground mb-2">No Maps Found</h3>
          <p className="text-text-muted mb-4">Create your first network topology map to visualize devices.</p>
          <Button variant="primary" onClick={() => setShowAddModal(true)}>
            <Plus className="w-5 h-5 mr-2" /> Create Map
          </Button>
        </div>
      )}

      <Modal
        isOpen={showAddModal}
        onClose={() => setShowAddModal(false)}
        title="Create New Map"
        icon={<Plus className="w-5 h-5" />}
        size="md"
        footer={
          <>
            <Button variant="secondary" type="button" onClick={() => setShowAddModal(false)}>Cancel</Button>
            <Button variant="primary" form="create-map-form" type="submit">Create</Button>
          </>
        }
      >
        <form id="create-map-form" onSubmit={createMap}>
          <label className="block text-sm font-bold text-text-muted mb-1">Map Name *</label>
          <input type="text" required value={newMapName}
            onChange={e => setNewMapName(e.target.value)}
            className="w-full bg-bg-card border border-border-subtle text-foreground rounded-2xl px-3 py-2 focus:outline-none focus:border-accent-primary"
            autoFocus />
        </form>
      </Modal>

      <VisibilityModal
        isOpen={showVisibilityModal}
        devices={devices}
        toggleVisibility={toggleVisibility}
        onClose={() => setShowVisibilityModal(false)}
      />

    </div>
  );
}
export default Maps;
