"use client";

import { useEffect, useState, useCallback } from "react";
import { Users, Search, UserPlus, Shield, Key, Trash, Power, ShieldAlert, ShieldCheck } from "lucide-react";

interface User {
  id: number;
  username: string;
  role: string;
  email: string;
  is_active: boolean;
}

export default function UsersPage() {
  const [users, setUsers] = useState<User[]>([]);
  const [loading, setLoading] = useState(true);
  
  // Filters
  const [search, setSearch] = useState('');
  const [roleFilter, setRoleFilter] = useState('');
  
  // UI State
  const [showAddModal, setShowAddModal] = useState(false);
  const [notification, setNotification] = useState<{type: 'success' | 'error', message: string} | null>(null);

  // Add User Form State
  const [newUsername, setNewUsername] = useState('');
  const [newEmail, setNewEmail] = useState('');
  const [newPassword, setNewPassword] = useState('');
  const [newRole, setNewRole] = useState('analyst');

  const fetchUsers = useCallback(() => {
    setLoading(true);
    const params = new URLSearchParams({
      search: search,
      role: roleFilter
    });
    
    fetch(`/api/get_users.php?${params.toString()}`, { credentials: "include" })
      .then(res => {
        if (res.status === 401 || res.status === 403) {
          window.location.href = '/home'; // Unauthorized, redirect
        }
        return res.json();
      })
      .then(data => {
        if (data.users) {
          setUsers(data.users);
        }
        setLoading(false);
      })
      .catch(err => {
        console.error(err);
        setLoading(false);
      });
  }, [search, roleFilter]);

  useEffect(() => {
    fetchUsers();
  }, [fetchUsers]);

  const showNotification = (type: 'success' | 'error', message: string) => {
    setNotification({ type, message });
    setTimeout(() => setNotification(null), 5000);
  };

  const handleAction = async (action: string, id?: number, extraData?: any) => {
    const payload = { action, id, ...extraData };
    try {
      const res = await fetch('/api/get_users.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload),
        credentials: "include"
      });
      const data = await res.json();
      if (data.success) {
        showNotification('success', data.success);
        fetchUsers();
        if (action === 'create') {
          setShowAddModal(false);
          setNewUsername('');
          setNewPassword('');
          setNewEmail('');
        }
      } else if (data.error) {
        showNotification('error', data.error);
      }
    } catch (err: any) {
      showNotification('error', err.message);
    }
  };

  const getRoleBadge = (role: string) => {
    switch (role.toLowerCase()) {
      case 'admin': return <span className="bg-red-900/50 text-red-400 border border-red-500/50 px-2 py-0.5 rounded-2xl text-xs font-bold uppercase"><ShieldAlert className="w-3 h-3 inline mr-1"/> Admin</span>;
      case 'analyst': return <span className="bg-blue-900/50 text-blue-400 border border-blue-500/50 px-2 py-0.5 rounded-2xl text-xs font-bold uppercase"><ShieldCheck className="w-3 h-3 inline mr-1"/> Analyst</span>;
      case 'operator': return <span className="bg-cyan-900/50 text-cyan-400 border border-cyan-500/50 px-2 py-0.5 rounded-2xl text-xs font-bold uppercase"><Shield className="w-3 h-3 inline mr-1"/> Operator</span>;
      default: return <span className="bg-gray-800 text-gray-300 px-2 py-1 rounded-2xl text-xs">{role}</span>;
    }
  };

  return (
    <div className="space-y-6 max-w-7xl mx-auto relative">
      
      {/* Notification Toast */}
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
            <Users className="w-8 h-8 mr-3 text-accent-primary" /> User Management
          </h1>
          <p className="text-text-muted mt-1 text-lg">Manage SOC analysts, operators, and administrators.</p>
        </div>
        <button 
          onClick={() => setShowAddModal(true)}
          className="bg-accent-primary hover:bg-accent-hover text-foreground px-4 py-2 rounded-2xl font-bold flex items-center transition-colors"
        >
          <UserPlus className="w-5 h-5 mr-2" /> Add New User
        </button>
      </div>

      <div className="bg-bg-raised border border-border-subtle rounded-2xl overflow-hidden flex flex-col h-[650px]">
        
        {/* Filters Bar */}
        <div className="p-4 border-b border-border-subtle bg-bg-main flex gap-4 items-center justify-between">
          <div className="relative flex-1 max-w-md">
            <Search className="w-4 h-4 absolute left-3 top-1/2 -translate-y-1/2 text-text-muted" />
            <input 
              type="text" 
              placeholder="Search username or email..." 
              value={search} onChange={(e) => setSearch(e.target.value)}
              className="w-full bg-bg-card border border-border-subtle rounded-2xl py-2 pl-10 pr-3 text-foreground focus:outline-none focus:border-accent-primary" 
            />
          </div>
          <select 
            value={roleFilter} onChange={(e) => setRoleFilter(e.target.value)}
            className="bg-bg-card border border-border-subtle rounded-2xl py-2 px-4 text-foreground focus:outline-none focus:border-accent-primary cursor-pointer"
          >
            <option value="">All Roles</option>
            <option value="admin">Administrators</option>
            <option value="analyst">Analysts</option>
            <option value="operator">Operators</option>
          </select>
        </div>

        <div className="flex-1 overflow-auto custom-scrollbar">
          <table className="w-full text-left text-sm whitespace-nowrap">
            <thead className="bg-bg-main text-text-muted uppercase text-xs tracking-wider border-b border-border-subtle sticky top-0 z-10">
              <tr>
                <th className="px-6 py-4 font-bold">Username</th>
                <th className="px-6 py-4 font-bold">Email</th>
                <th className="px-6 py-4 font-bold text-center">Role</th>
                <th className="px-6 py-4 font-bold text-center">Status</th>
                <th className="px-6 py-4 font-bold text-right">Actions</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-border-subtle">
              {loading && users.length === 0 && (
                <tr>
                  <td colSpan={5} className="px-6 py-20 text-center">
                    <div className="w-8 h-8 border-4 border-border-subtle border-t-accent-primary rounded-full animate-spin mx-auto"></div>
                  </td>
                </tr>
              )}
              
              {users.map((user) => (
                <tr key={user.id} className="hover:bg-bg-card/50 transition-colors">
                  <td className="px-6 py-4">
                    <div className="font-bold text-foreground text-base">{user.username}</div>
                    {user.username === 'admin' && <span className="text-text-muted text-xs">Built-in account</span>}
                  </td>
                  <td className="px-6 py-4 text-[#d8cce2]">{user.email || <span className="text-gray-500 italic">No email set</span>}</td>
                  <td className="px-6 py-4 text-center">{getRoleBadge(user.role)}</td>
                  <td className="px-6 py-4 text-center">
                    <span className={`px-2 py-1 rounded-2xl text-xs font-bold ${user.is_active ? 'bg-green-900/30 text-green-400' : 'bg-gray-800 text-gray-500'}`}>
                      {user.is_active ? 'ACTIVE' : 'DISABLED'}
                    </span>
                  </td>
                  <td className="px-6 py-4 text-right">
                    <div className="flex items-center justify-end space-x-2">
                      <button 
                        onClick={() => handleAction('reset_password', user.id)}
                        className="p-2 bg-bg-card hover:bg-yellow-600 text-yellow-400 hover:text-foreground rounded-2xl border border-border-subtle hover:border-yellow-500 transition-colors"
                        title="Reset Password to newpassword123"
                      >
                        <Key className="w-4 h-4" />
                      </button>

                      {user.username !== 'admin' && (
                        <>
                          <button 
                            onClick={() => handleAction(user.is_active ? 'disable_user' : 'enable_user', user.id)}
                            className={`p-2 rounded-2xl border transition-colors ${
                              user.is_active 
                                ? 'bg-bg-card hover:bg-orange-600 text-orange-400 border-border-subtle hover:border-orange-500' 
                                : 'bg-bg-card hover:bg-green-600 text-green-400 border-border-subtle hover:border-green-500'
                            }`}
                            title={user.is_active ? "Disable Account" : "Enable Account"}
                          >
                            <Power className="w-4 h-4" />
                          </button>
                          
                          <select 
                            onChange={(e) => handleAction('change_role', user.id, { new_role: e.target.value })}
                            value={user.role}
                            className="bg-bg-card border border-border-subtle rounded-2xl py-1.5 px-2 text-xs text-foreground focus:outline-none focus:border-accent-primary"
                          >
                            <option value="admin">Admin</option>
                            <option value="analyst">Analyst</option>
                            <option value="operator">Operator</option>
                          </select>

                          <button 
                            onClick={() => {
                              if (confirm(`Are you sure you want to permanently delete user ${user.username}?`)) {
                                handleAction('delete', user.id);
                              }
                            }}
                            className="p-2 bg-bg-card hover:bg-red-600 text-red-400 hover:text-foreground rounded-2xl border border-border-subtle hover:border-red-500 transition-colors"
                            title="Delete User"
                          >
                            <Trash className="w-4 h-4" />
                          </button>
                        </>
                      )}
                    </div>
                  </td>
                </tr>
              ))}

              {!loading && users.length === 0 && (
                <tr>
                  <td colSpan={5} className="px-6 py-20 text-center text-text-muted text-lg">
                    No users found matching your criteria.
                  </td>
                </tr>
              )}
            </tbody>
          </table>
        </div>
      </div>

      {/* Add User Modal */}
      {showAddModal && (
        <div className="fixed inset-0 bg-black/70 backdrop- z-50 flex items-center justify-center p-4">
          <div className="bg-bg-main border border-border-subtle rounded-2xl w-full max-w-md overflow-hidden">
            <div className="bg-bg-raised p-4 border-b border-border-subtle flex justify-between items-center">
              <h3 className="text-xl font-bold text-foreground flex items-center"><UserPlus className="w-5 h-5 mr-2 text-accent-primary" /> Create New User</h3>
              <button onClick={() => setShowAddModal(false)} className="text-text-muted hover:text-foreground transition-colors">
                &times;
              </button>
            </div>
            <form onSubmit={(e) => { e.preventDefault(); handleAction('create', undefined, { username: newUsername, password: newPassword, email: newEmail, role: newRole }); }} className="p-6 space-y-4">
              <div>
                <label className="block text-sm font-medium text-text-muted mb-1">Username</label>
                <input 
                  type="text" required value={newUsername} onChange={e => setNewUsername(e.target.value)}
                  className="w-full bg-bg-card border border-border-subtle text-foreground rounded-2xl px-3 py-2 focus:outline-none focus:border-accent-primary"
                />
              </div>
              <div>
                <label className="block text-sm font-medium text-text-muted mb-1">Email Address</label>
                <input 
                  type="email" value={newEmail} onChange={e => setNewEmail(e.target.value)}
                  className="w-full bg-bg-card border border-border-subtle text-foreground rounded-2xl px-3 py-2 focus:outline-none focus:border-accent-primary"
                />
              </div>
              <div>
                <label className="block text-sm font-medium text-text-muted mb-1">Password</label>
                <input 
                  type="password" required value={newPassword} onChange={e => setNewPassword(e.target.value)}
                  className="w-full bg-bg-card border border-border-subtle text-foreground rounded-2xl px-3 py-2 focus:outline-none focus:border-accent-primary"
                />
              </div>
              <div>
                <label className="block text-sm font-medium text-text-muted mb-1">Role</label>
                <select 
                  value={newRole} onChange={e => setNewRole(e.target.value)}
                  className="w-full bg-bg-card border border-border-subtle text-foreground rounded-2xl px-3 py-2 focus:outline-none focus:border-accent-primary"
                >
                  <option value="admin">Administrator (Full Access)</option>
                  <option value="analyst">Analyst (SOC/NOC tools)</option>
                  <option value="operator">Operator (View only metrics)</option>
                </select>
              </div>
              <div className="pt-4 flex justify-end space-x-3">
                <button type="button" onClick={() => setShowAddModal(false)} className="px-4 py-2 bg-transparent text-text-muted hover:text-foreground rounded-2xl transition-colors">
                  Cancel
                </button>
                <button type="submit" className="px-6 py-2 bg-accent-primary hover:bg-accent-hover text-foreground rounded-2xl font-bold transition-colors">
                  Create User
                </button>
              </div>
            </form>
          </div>
        </div>
      )}

    </div>
  );
}
