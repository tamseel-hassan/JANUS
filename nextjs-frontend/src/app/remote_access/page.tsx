import { Metadata } from "next";
import RemoteAccess from "@/components/remote_access/RemoteAccess";

export const metadata: Metadata = {
  title: "Remote Access Monitor | JANUS",
};

export default function RemoteAccessPage() {
  return <RemoteAccess />;
}
