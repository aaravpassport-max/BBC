use crate::models::BusinessRow;
use crate::storage::export_path;
use rust_xlsxwriter::{Format, Workbook};
use std::fs::File;
use std::io::Write;
use std::path::PathBuf;

const HEADERS: [&str; 20] = [
    "Business name",
    "Category",
    "Address",
    "City",
    "State",
    "Country",
    "Phone",
    "Website",
    "Email",
    "Rating",
    "Review count",
    "Google Maps URL",
    "Latitude",
    "Longitude",
    "Opening hours",
    "Description",
    "Place ID",
    "Search keyword",
    "Search location",
    "Scrape timestamp",
];

pub fn export_csv(folder: &str, filename: &str, rows: &[BusinessRow]) -> Result<PathBuf, String> {
    let path = export_path(folder, filename);
    let mut wtr = csv::WriterBuilder::new()
        .has_headers(true)
        .from_path(&path)
        .map_err(|e| e.to_string())?;
    wtr.write_record(HEADERS).map_err(|e| e.to_string())?;
    for r in rows {
        wtr.write_record([
            &r.title,
            &r.category,
            &r.address,
            &r.city,
            &r.state,
            &r.country,
            &r.phone,
            &r.website,
            &r.email,
            &r.review_rating,
            &r.review_count,
            &r.maps_url,
            &r.latitude,
            &r.longitude,
            &r.open_hours,
            &r.description,
            &r.place_id,
            &r.search_keyword,
            &r.search_location,
            &r.scraped_at,
        ])
        .map_err(|e| e.to_string())?;
    }
    wtr.flush().map_err(|e| e.to_string())?;
    Ok(path)
}

pub fn export_json(folder: &str, filename: &str, rows: &[BusinessRow]) -> Result<PathBuf, String> {
    let path = export_path(folder, filename);
    let json = serde_json::to_string_pretty(rows).map_err(|e| e.to_string())?;
    let mut f = File::create(&path).map_err(|e| e.to_string())?;
    f.write_all(json.as_bytes()).map_err(|e| e.to_string())?;
    Ok(path)
}

pub fn export_xlsx(folder: &str, filename: &str, rows: &[BusinessRow]) -> Result<PathBuf, String> {
    let path = export_path(folder, filename);
    let mut workbook = Workbook::new();
    let sheet = workbook.add_worksheet();
    sheet.set_name("Results").map_err(|e| e.to_string())?;
    let header_fmt = Format::new().set_bold();
    for (col, h) in HEADERS.iter().enumerate() {
        sheet
            .write_string_with_format(0, col as u16, *h, &header_fmt)
            .map_err(|e| e.to_string())?;
    }
    for (i, r) in rows.iter().enumerate() {
        let row = (i + 1) as u32;
        let vals = [
            &r.title,
            &r.category,
            &r.address,
            &r.city,
            &r.state,
            &r.country,
            &r.phone,
            &r.website,
            &r.email,
            &r.review_rating,
            &r.review_count,
            &r.maps_url,
            &r.latitude,
            &r.longitude,
            &r.open_hours,
            &r.description,
            &r.place_id,
            &r.search_keyword,
            &r.search_location,
            &r.scraped_at,
        ];
        for (col, v) in vals.iter().enumerate() {
            sheet
                .write_string(row, col as u16, *v)
                .map_err(|e| e.to_string())?;
        }
    }
    workbook.save(&path).map_err(|e| e.to_string())?;
    Ok(path)
}

pub fn safe_filename(base: &str) -> String {
    base.chars()
        .map(|c| if c.is_ascii_alphanumeric() || c == '-' || c == '_' { c } else { '_' })
        .collect()
}
