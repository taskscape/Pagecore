# Featured images - zakres implementacji

## Model danych

Pagecore pozostaje systemem bez bazy danych. Obrazek wyróżniający wpisu jest przechowywany w front matter pliku Markdown:

```yaml
---
title: Przykładowy wpis
date: 2026-07-01
category: news
excerpt: Krótki opis wpisu.
image: /uploads/2026/07/przykladowy-obraz.webp
imageAlt: Opis obrazka
---
```

W MVP można używać istniejącego pola `image`. Pole `imageAlt` należy dodać jako następny krok, jeśli potrzebujemy osobnego tekstu alternatywnego dla featured image.

## Backend / CMS

- `cms_posts()` powinno zwracać `image` dla list wpisów.
- `cms_post()` powinno zwracać `image` dla strony pojedynczego wpisu.
- Zapis metadanych wpisu powinien obsługiwać pole `image`.
- Po zmianie metadanych wpisu indeks wpisów powinien zostać zregenerowany.
- Warto dodać walidację, żeby `image` wskazywało tylko na bezpieczne lokalne URL-e z uploads albo inne jawnie dozwolone ścieżki.

## Edytor wpisu

- W formularzu metadanych wpisu dodać pole `Featured image URL`.
- Pole powinno wczytywać wartość z front matter i zapisywać ją razem z innymi metadanymi.
- Następny krok: dodać przycisk wyboru pliku z Media Library bezpośrednio do pola featured image.
- Następny krok: pokazać miniaturę aktualnie wybranego obrazka.

## Renderowanie

- Karty wpisów powinny pokazywać obrazek, jeśli `image` jest ustawione.
- Strona pojedynczego wpisu powinna pokazywać obrazek pod tytułem albo leadem.
- Jeżeli `image` jest puste, layout nie powinien zostawiać pustego miejsca.
- Następny krok: użyć `image` jako `og:image` i `twitter:image`.

## Sample-site

- Dodać przykładowy wpis z polem `image`.
- Dodać fixture obrazka w uploads.
- Dodać stronę demonstracyjną `/sample-site/showcase/`, która pokazuje file-based model featured images.
- Dodać test e2e sprawdzający, że obrazek pojawia się na liście i na stronie wpisu.

## Migracja istniejących publikacji

Dla dużych serwisów migracja powinna być automatyczna:

- przejść po plikach `content/posts/*.md`;
- pominąć wpisy, które już mają `image`;
- spróbować znaleźć pierwszy obrazek w treści Markdown;
- zapisać znaleziony URL jako `image`;
- wygenerować raport wpisów, dla których nie znaleziono obrazka;
- po migracji zregenerować indeks wpisów.

## Testy

- test renderowania listy wpisów z obrazkiem;
- test renderowania strony pojedynczego wpisu z obrazkiem;
- test zapisu pola `image` z edytora metadanych;
- test, że wpis bez `image` nadal renderuje się poprawnie;
- test migracji, jeśli dodajemy skrypt migracyjny.
