"use client";

import { useState, useEffect } from 'react';
import { User, Lock, Mail, AlertCircle, CheckCircle2 } from 'lucide-react';
import { Button } from "@/components/ui/Button";
import { userService } from "@/services/user/userService";

export function Profile() {
  const [profile, setProfile] = useState({ username: '', email: '' });
  const [loading, setLoading] = useState(true);
  
  const [passwordForm, setPasswordForm] = useState({
    current_password: '',
    new_password: '',
    confirm_password: ''
  });
  
  const [emailForm, setEmailForm] = useState({
    new_email: ''
  });

  const [message, setMessage] = useState<{type: 'error'|'success', text: string} | null>(null);
  const [isSubmitting, setIsSubmitting] = useState(false);

  useEffect(() => {
    fetchProfile();
  }, []);

  const fetchProfile = async () => {
    const data = await userService.getProfile();
    if (data && data.username) {
      setProfile({ username: data.username, email: data.email || '' });
      setEmailForm({ new_email: data.email || '' });
    }
    setLoading(false);
  };

  const handlePasswordSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    setIsSubmitting(true);
    setMessage(null);
    try {
      const data = await userService.updateProfile({
        action: 'change_password',
        ...passwordForm
      });
      if (data?.error) {
        setMessage({ type: 'error', text: data.error });
      } else if (data?.success) {
        setMessage({ type: 'success', text: data.success });
        setPasswordForm({ current_password: '', new_password: '', confirm_password: '' });
      }
    } catch (err) {
      setMessage({ type: 'error', text: 'Network error occurred.' });
    } finally {
      setIsSubmitting(false);
    }
  };

  const handleEmailSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    setIsSubmitting(true);
    setMessage(null);
    try {
      const data = await userService.updateProfile({
        action: 'change_email',
        ...emailForm
      });
      if (data?.error) {
        setMessage({ type: 'error', text: data.error });
      } else if (data?.success) {
        setMessage({ type: 'success', text: data.success });
        setProfile(prev => ({ ...prev, email: emailForm.new_email }));
      }
    } catch (err) {
      setMessage({ type: 'error', text: 'Network error occurred.' });
    } finally {
      setIsSubmitting(false);
    }
  };

  if (loading) {
    return <div className="p-6 text-slate-400">Loading profile...</div>;
  }

  return (
    <div className="p-6 max-w-4xl mx-auto space-y-6">
      <div className="flex items-center gap-4">
        <div className="w-16 h-16 bg-purple-500/20 rounded-2xl flex items-center justify-center border border-purple-500/30">
          <User className="w-8 h-8 text-purple-400" />
        </div>
        <div>
          <h1 className="text-2xl font-bold text-foreground">My Profile</h1>
          <p className="text-slate-400 mt-1">Manage your account settings and security</p>
        </div>
      </div>

      {message && (
        <div className={`p-4 rounded-2xl flex items-center gap-3 border ${
          message.type === 'error' 
            ? 'bg-red-500/10 border-red-500/30 text-red-400' 
            : 'bg-emerald-500/10 border-emerald-500/30 text-emerald-400'
        }`}>
          {message.type === 'error' ? <AlertCircle className="w-5 h-5 flex-shrink-0" /> : <CheckCircle2 className="w-5 h-5 flex-shrink-0" />}
          <p className="text-sm font-medium">{message.text}</p>
        </div>
      )}

      <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
        {/* Profile Info & Email Change */}
        <div className="bg-slate-800 rounded-2xl border border-slate-700 p-6">
          <div className="flex items-center gap-3 mb-6">
            <Mail className="w-5 h-5 text-purple-400" />
            <h2 className="text-lg font-semibold text-foreground">Account Details</h2>
          </div>
          
          <div className="mb-6">
            <label className="block text-sm font-medium text-slate-400 mb-1">Username</label>
            <div className="w-full bg-slate-900 border border-slate-700 rounded-2xl px-4 py-2.5 text-slate-300">
              {profile.username}
            </div>
            <p className="text-xs text-slate-500 mt-2">Username cannot be changed.</p>
          </div>

          <form onSubmit={handleEmailSubmit} className="space-y-4 pt-6 border-t border-slate-700/50">
            <div>
              <label className="block text-sm font-medium text-slate-300 mb-2">Email Address</label>
              <input
                type="email"
                value={emailForm.new_email}
                onChange={e => setEmailForm({ new_email: e.target.value })}
                className="w-full bg-slate-900 border border-slate-700 rounded-2xl px-4 py-2.5 text-foreground focus:outline-none focus:border-purple-500 focus:ring-1 focus:ring-purple-500 transition-all"
                required
              />
            </div>
            <Button
              type="submit"
              variant="secondary"
              className="w-full py-2.5"
              disabled={isSubmitting || emailForm.new_email === profile.email}
              isLoading={isSubmitting}
            >
              Update Email
            </Button>
          </form>
        </div>

        {/* Password Change */}
        <div className="bg-slate-800 rounded-2xl border border-slate-700 p-6">
          <div className="flex items-center gap-3 mb-6">
            <Lock className="w-5 h-5 text-purple-400" />
            <h2 className="text-lg font-semibold text-foreground">Change Password</h2>
          </div>
          
          <form onSubmit={handlePasswordSubmit} className="space-y-4">
            <div>
              <label className="block text-sm font-medium text-slate-300 mb-2">Current Password</label>
              <input
                type="password"
                value={passwordForm.current_password}
                onChange={e => setPasswordForm({ ...passwordForm, current_password: e.target.value })}
                className="w-full bg-slate-900 border border-slate-700 rounded-2xl px-4 py-2.5 text-foreground focus:outline-none focus:border-purple-500 focus:ring-1 focus:ring-purple-500 transition-all"
                required
              />
            </div>
            <div>
              <label className="block text-sm font-medium text-slate-300 mb-2">New Password</label>
              <input
                type="password"
                value={passwordForm.new_password}
                onChange={e => setPasswordForm({ ...passwordForm, new_password: e.target.value })}
                className="w-full bg-slate-900 border border-slate-700 rounded-2xl px-4 py-2.5 text-foreground focus:outline-none focus:border-purple-500 focus:ring-1 focus:ring-purple-500 transition-all"
                required
                minLength={8}
              />
              <p className="text-xs text-slate-500 mt-1">Must be at least 8 characters long.</p>
            </div>
            <div>
              <label className="block text-sm font-medium text-slate-300 mb-2">Confirm New Password</label>
              <input
                type="password"
                value={passwordForm.confirm_password}
                onChange={e => setPasswordForm({ ...passwordForm, confirm_password: e.target.value })}
                className="w-full bg-slate-900 border border-slate-700 rounded-2xl px-4 py-2.5 text-foreground focus:outline-none focus:border-purple-500 focus:ring-1 focus:ring-purple-500 transition-all"
                required
                minLength={8}
              />
            </div>
            <div className="pt-2">
              <Button
                type="submit"
                variant="primary"
                className="w-full py-2.5"
                disabled={isSubmitting || !passwordForm.current_password || !passwordForm.new_password}
                isLoading={isSubmitting}
              >
                Change Password
              </Button>
            </div>
          </form>
        </div>
      </div>
    </div>
  );
}
export default Profile;
