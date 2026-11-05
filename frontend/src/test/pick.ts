import userEvent from "@testing-library/user-event";

/**
 * Chooses an option of a dropdown, native or searchable. `option` is the option's value or its visible text, like userEvent.selectOptions.
 */
export async function pick(element: HTMLElement, option: string) {
  if (element.tagName === "SELECT") {
    await userEvent.selectOptions(element, option);
    return;
  }
  await userEvent.click(element);
  const list = document.getElementById(element.getAttribute("aria-controls")!)!;
  const row = Array.from(
    list.querySelectorAll<HTMLElement>('[role="option"]'),
  ).find((o) => o.dataset.value === option || o.textContent === option);
  if (!row) throw new Error(`No option "${option}" in the dropdown.`);
  await userEvent.click(row);
}

/** The visible texts of the options of a dropdown, read by opening it and closing it again (a searchable list shows its rows only open). */
export async function optionsOf(element: HTMLElement): Promise<string[]> {
  if (element.tagName === "SELECT")
    return Array.from((element as HTMLSelectElement).options).map(
      (o) => o.textContent ?? "",
    );
  await userEvent.click(element);
  const list = document.getElementById(element.getAttribute("aria-controls")!)!;
  const texts = Array.from(
    list.querySelectorAll<HTMLElement>('[role="option"]'),
  ).map((o) => o.textContent ?? "");
  await userEvent.keyboard("{Escape}");
  return texts;
}
