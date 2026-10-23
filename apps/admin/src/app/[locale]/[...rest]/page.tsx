import { notFound } from "next/navigation";

/** Unknown paths render the localised not-found page instead of Next's default one. */
export default function CatchAllPage() {
  notFound();
}
