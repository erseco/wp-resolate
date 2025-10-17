import re
from playwright.sync_api import Page, expect

def test_dynamic_fields_metabox(page: Page):
    # Log in to WordPress
    page.goto("http://localhost:8889/wp-login.php")
    page.get_by_label("Username or Email Address").fill("admin")
    page.get_by_label("Password").fill("password")
    page.get_by_role("button", name="Log In").click()

    # Create a test template
    page.goto("http://localhost:8889/wp-admin/upload.php")
    with page.expect_file_chooser() as fc_info:
        page.get_by_role("link", name="Add New").click()
    file_chooser = fc_info.value
    file_chooser.set_files("fixtures/plantilla.odt")

    # Get the attachment ID from the URL
    expect(page).to_have_url(re.compile(r"post\.php\?post=\d+&action=edit"))
    attachment_id = page.url.split("post=")[1].split("&")[0]

    # Create a new document type
    page.goto("http://localhost:8889/wp-admin/edit-tags.php?taxonomy=resolate_doc_type&post_type=resolate_doc")
    page.get_by_label("Name").fill("Test ODT Type")
    page.locator("#resolate_type_template_id").fill(attachment_id)
    page.get_by_role("button", name="Add New Document Type").click()

    # Create a new document
    page.goto("http://localhost:8889/wp-admin/post-new.php?post_type=resolate_doc")
    page.get_by_label("Add title").fill("Test Document")
    page.get_by_label("Tipo de documento").select_option(label="Test ODT Type")
    page.get_by_role("button", name="Save Draft").click()

    # Verify the dynamic fields meta box
    expect(page.get_by_role("heading", name="Campos del Documento (ODT)")).to_be_visible()

    # Fill in the fields
    page.get_by_label("Full name").fill("Jules Verne")
    page.get_by_label("amount").fill("123.45")
    page.get_by_label("due date").fill("2025-12-31")
    page.get_by_label("Optional notes").fill("These are some notes.")

    # Screenshot the meta box
    page.locator("#resolate_dynamic_fields").screenshot(path="jules-scratch/verification/dynamic-fields.png")

    # Save the post and check for validation errors
    page.get_by_role("button", name="Save Draft").click()
    expect(page.locator(".notice-error")).not_to_be_visible()