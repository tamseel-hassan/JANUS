interface LoadingSpinnerProps {
  /** sm = w-8 h-8, md = w-10 h-10 (default), lg = w-12 h-12 */
  size?: "sm" | "md" | "lg";
  /** Wraps the spinner in a centered flex container with min-height */
  fullPage?: boolean;
}

const sizeMap = {
  sm: "w-8 h-8",
  md: "w-10 h-10",
  lg: "w-12 h-12",
};

export function LoadingSpinner({ size = "md", fullPage = false }: LoadingSpinnerProps) {
  const spinner = (
    <div
      className={`${sizeMap[size]} border-4 border-border-subtle border-t-accent-primary rounded-full animate-spin`}
    />
  );

  if (fullPage) {
    return (
      <div className="flex justify-center items-center h-64">
        {spinner}
      </div>
    );
  }

  return spinner;
}

export default LoadingSpinner;
