"use client";

import { useEffect, useState, useCallback } from "react";
import { Users as UsersIcon, UserPlus, Shield, Key, Trash, Power, ShieldAlert, ShieldCheck } from "lucide-react";
import CustomSelect from "@/components/ui/CustomSelect";
import { User } from "@/types/users";
import { SearchInput } from "@/components/ui/SearchInput";
import { PageHeader } from "@/components/ui/PageHeader";
import { SectionCard } from "@/components/ui/SectionCard";
import { Button } from "@/components/ui/Button";
import { Modal } from "@/components/ui/Modal";
import { notify, promptService } from "@/services/feedback/feedbackService";

import { userService } from "@/services/user/userService";

export function Users() {
  const [users, setUsers] = useState<User[]>([]);
  const [loading, setLoading] = useState(true);
  
  const [search, setSearch] = useState('');
  const [roleFilter, setRoleFilter] = useState('');
  
  const [showAddModal, setShowAddModal] = useState(false);
  const [newUsername, setNewUsername] = useState('');
  const [newEmail, setNewEmail] = useState('');
  const [newPassword, setNewPassword] = useState('');
  const [newRole, setNewRole] = useState('analyst');

  const fetchUsers = useCallback(() => {
    setLoading(true);
    userService.getUsers(search, roleFilter).then((data) => {
      if (data?.users) setUsers(data.users);
      setLoading(false);
    });
  }, [search, roleFilter]);

  useEffect(() => { fetchUsers(); }, [fetchUsers]);

  const handleAction = async (action: string, id?: number, extraData?: any) => {
    try {
      const data = await userService.executeAction(action, id, extraData);
      if (data?.success) {
        notify.success(data.success);
        fetchUsers();
        if (action === 'create') {
          setShowAddModal(false);
          setNewUsername(''); setNewPassword(''); setNewEmail('');
        }
      } else if (data?.error) {
        notify.error(data.error);
      }
    } catch (err: any) {
      notify.error(err.message || "Action failed");
    }
  };

  const getRoleBadge = (role: string) => {
    switch (role.toLowerCase()) {
      case 'admin':    return <span className="bg-red-500/10 text-red-600 dark:text-red-400 border border-red-500/30 px-2 py-0.5 rounded-2xl text-xs font-bold uppercase"><ShieldAlert className="w-3 h-3 inline mr-1"/>Admin</span>;
      case 'analyst':  return <span className="bg-blue-500/10 text-blue-600 dark:text-blue-400 border border-blue-500/30 px-2 py-0.5 rounded-2xl text-xs font-bold uppercase"><ShieldCheck className="w-3 h-3 inline mr-1"/>Analyst</span>;
      case 'operator': return <span className="bg-cyan-500/10 text-cyan-700 dark:text-cyan-400 border border-cyan-500/30 px-2 py-0.5 rounded-2xl text-xs font-bold uppercase"><Shield className="w-3 h-3 inline mr-1"/>Operator</span>;
      default:         return <span className="bg-bg-raised text-text-muted border border-border-subtle px-2 py-0.5 rounded-2xl text-xs">{role}</span>;
    }
  };

  const filteredUsers = users.filter(user =>
    user.username.toLowerCase().includes(search.toLowerCase()) ||
    (user.email && user.email.toLowerCase().includes(search.toLowerCase()))
  );

  return (
    <div className="space-y-6 max-w-7xl mx-auto relative">

      <PageHeader
        title={<><UsersIcon className="w-8 h-8 inline-block mr-3 text-accent-primary" />User Management</>}
        subtitle="Manage SOC analysts, operators, and administrators."
        actions={
          <Button onClick={() => setShowAddModal(true)}>
            <UserPlus className="w-5 h-5 mr-2" /> Add New User
          </Button>
        }
      />

      <SectionCard
        className="flex flex-col h-[650px]"
        header={
          <>
            <SearchInput
              value={search}
              onChange={setSearch}
              placeholder="Search username or email..."
              className="flex-1 max-w-md"
            />
            <CustomSelect
              value={roleFilter}
              onChange={setRoleFilter}
              options={[
                { value: "", label: "All Roles" },
                { value: "admin", label: "Administrators" },
                { value: "analyst", label: "Analysts" },
                { value: "operator", label: "Operators" }
              ]}
            />
          </>
        }
      >
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
              
              {filteredUsers.map((user) => (
                <tr key={user.id} className="hover:bg-bg-card/50 transition-colors">
                  <td className="px-6 py-4">
                    <div className="font-bold text-foreground text-base">{user.username}</div>
                    {user.username === 'admin' && <span className="text-text-muted text-xs">Built-in account</span>}
                  </td>
                  <td className="px-6 py-4 text-text-muted font-normal">{user.email || <span className="text-text-muted/60 italic">No email set</span>}</td>
                  <td className="px-6 py-4 text-center">{getRoleBadge(user.role)}</td>
                  <td className="px-6 py-4 text-center">
                    <span className={`px-2.5 py-1 rounded-2xl text-xs font-bold ${user.is_active ? 'bg-emerald-500/10 text-emerald-700 dark:text-emerald-400 border border-emerald-500/30' : 'bg-slate-500/10 text-slate-600 dark:text-slate-400 border border-slate-500/30'}`}>
                      {user.is_active ? 'ACTIVE' : 'DISABLED'}
                    </span>
                  </td>
                  <td className="px-6 py-4 text-right">
                    <div className="flex items-center justify-end space-x-2">
                      <Button
                        variant="outline-warning"
                        size="icon"
                        onClick={() => handleAction('reset_password', user.id)}
                        title="Reset Password"
                      >
                        <Key className="w-4 h-4" />
                      </Button>

                      {user.username !== 'admin' && (
                        <>
                          <Button
                            variant={user.is_active ? "outline-warning" : "outline-success"}
                            size="icon"
                            onClick={() => handleAction(user.is_active ? 'disable_user' : 'enable_user', user.id)}
                            title={user.is_active ? "Disable Account" : "Enable Account"}
                            className={user.is_active ? "hover:bg-orange-600 text-orange-400 hover:border-orange-500" : ""}
                          >
                            <Power className="w-4 h-4" />
                          </Button>
                          
                          <CustomSelect
                            value={user.role}
                            onChange={(val) => handleAction('change_role', user.id, { new_role: val })}
                            options={[
                              { value: "admin", label: "Admin" },
                              { value: "analyst", label: "Analyst" },
                              { value: "operator", label: "Operator" }
                            ]}
                            className="min-w-[110px]"
                          />

                          <Button
                            variant="outline-danger"
                            size="icon"
                            onClick={async () => {
                              const ok = await promptService.confirm({
                                title: "Delete User",
                                description: `Are you sure you want to permanently delete user ${user.username}?`,
                                variant: 'destructive',
                                confirmText: "Delete"
                              });
                              if (ok) {
                                handleAction('delete', user.id);
                              }
                            }}
                            title="Delete User"
                          >
                            <Trash className="w-4 h-4" />
                          </Button>
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
      </SectionCard>

      <Modal
        isOpen={showAddModal}
        onClose={() => setShowAddModal(false)}
        title="Create New User"
        icon={<UserPlus className="w-5 h-5" />}
        size="md"
        footer={
          <>
            <Button variant="ghost" type="button" onClick={() => setShowAddModal(false)}>Cancel</Button>
            <Button variant="primary" form="create-user-form" type="submit">Create User</Button>
          </>
        }
      >
        <form
          id="create-user-form"
          onSubmit={(e) => {
            e.preventDefault();
            handleAction('create', undefined, { username: newUsername, password: newPassword, email: newEmail, role: newRole });
          }}
          className="space-y-4"
        >
          <div>
            <label className="block text-sm font-medium text-text-muted mb-1">Username</label>
            <input type="text" required value={newUsername} onChange={e => setNewUsername(e.target.value)}
              className="w-full bg-bg-card border border-border-subtle text-foreground rounded-2xl px-3 py-2 focus:outline-none focus:border-accent-primary" />
          </div>
          <div>
            <label className="block text-sm font-medium text-text-muted mb-1">Email Address</label>
            <input type="email" value={newEmail} onChange={e => setNewEmail(e.target.value)}
              className="w-full bg-bg-card border border-border-subtle text-foreground rounded-2xl px-3 py-2 focus:outline-none focus:border-accent-primary" />
          </div>
          <div>
            <label className="block text-sm font-medium text-text-muted mb-1">Password</label>
            <input type="password" required value={newPassword} onChange={e => setNewPassword(e.target.value)}
              className="w-full bg-bg-card border border-border-subtle text-foreground rounded-2xl px-3 py-2 focus:outline-none focus:border-accent-primary" />
          </div>
          <div>
            <label className="block text-sm font-medium text-text-muted mb-1">Role</label>
            <CustomSelect value={newRole} onChange={setNewRole} options={[
              { value: "admin", label: "Administrator (Full Access)" },
              { value: "analyst", label: "Analyst (SOC/NOC tools)" },
              { value: "operator", label: "Operator (View only metrics)" }
            ]} />
          </div>
        </form>
      </Modal>

    </div>
  );
}
export default Users;
