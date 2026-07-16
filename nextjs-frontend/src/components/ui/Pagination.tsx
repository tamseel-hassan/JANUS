import { Button } from "@/components/ui/Button";

interface PaginationProps {
  page: number;
  totalPages: number;
  /** Optional total record count to display */
  total?: number;
  onPrev: () => void;
  onNext: () => void;
}

export function Pagination({ page, totalPages, total, onPrev, onNext }: PaginationProps) {
  return (
    <div className="p-3 border-t border-border-subtle bg-bg-main flex justify-between items-center text-sm">
      <span className="text-text-muted">
        Showing page {page} of {totalPages || 1}
        {total !== undefined && ` (${total.toLocaleString()} total records)`}
      </span>
      <div className="flex gap-2">
        <Button
          variant="outline"
          size="sm"
          disabled={page <= 1}
          onClick={onPrev}
        >
          Prev
        </Button>
        <Button
          variant="outline"
          size="sm"
          disabled={page >= totalPages}
          onClick={onNext}
        >
          Next
        </Button>
      </div>
    </div>
  );
}

export default Pagination;
