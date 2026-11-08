// Catalogue browsing as anonymous visitors: home rails, listing, search, course and professor pages.
import { check, sleep } from "k6";
import { data, get, pick, stages, thresholds, visitor } from "./lib.js";

export const options = { stages: stages(), thresholds };

const SEARCHES = ["math", "physique", "anglais", "examen", "chimie", "histoire"];

export default function () {
  const h = visitor();
  check(get("/featured", h), { featured: (r) => r.status === 200 });
  check(get("/categories", h), { categories: (r) => r.status === 200 });

  const list = get("/courses?per_page=24", h, "/courses");
  check(list, { list: (r) => r.status === 200 });
  sleep(1);

  check(get(`/courses?q=${pick(SEARCHES)}`, h, "/courses?search"), { search: (r) => r.status === 200 });
  sleep(1);

  const slugs = (list.json("data") || []).map((c) => c.slug).filter(Boolean);
  const slug = slugs.length ? pick(slugs) : data().free_course.slug;
  const course = get(`/courses/${slug}`, h, "/courses/{slug}");
  check(course, { course: (r) => r.status === 200 });
  check(get(`/courses/${slug}/reviews`, h, "/courses/{slug}/reviews"), { reviews: (r) => r.status === 200 });

  const professor = course.json("data.professor.slug");
  if (professor) {
    check(get(`/professors/${professor}`, h, "/professors/{slug}"), { professor: (r) => r.status === 200 });
  }
  sleep(2);
}
