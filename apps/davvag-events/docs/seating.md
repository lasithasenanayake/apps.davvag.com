# Seating guide (1.2.0)

Open an event's Seating screen. Existing tables, guest records and assignments remain available.

1. Use **Add section** for room areas such as Main floor, Balcony or Family. Choose a name and colour. Section chips filter the plan.
2. Use **Add tables** to open the popup. Pick Round, Oval, Square or Rectangle, a starting table number, the number of tables and seats per table. Choose a section and add the tables. Numbers and seat labels are generated automatically.
3. For ceremony or theatre seating, use **Add seat plan** instead. Pick rows, seats per row, the first row letter and numbering style: A1/A2/B1, or one continuous sequence. This adds to the current plan.
4. Use **Add group** to collect family, friends or colleagues. Choose members, a colour, whether they must share one table/row, and an optional section for automatic suggestions. Changing a group never moves existing assignments.
5. Select a table on the map to see its numbered seats. Select a seat or use **Seat guest** in the waiting list to open the assignment popup. Occupied seats and incompatible accessible seats are unavailable. Moving a locked assignment needs the explicit unlock option.
6. Drag tables/rows on the map to arrange the room. **Edit** also exposes position, rotation and seat labels. Seat list provides buttons for keyboard and touch use. Print switches to the seat list.
7. **Suggest seats** previews placement. Review any groups needing attention, then apply. Existing seats are preserved; configured groups keep their section and keep-together rules.

Deleting an occupied table requires confirming release of its assignments. Guests return to the waiting list. Deleting a populated section requires moving tables/rows to Unsectioned; guest records and assignments are preserved. Group deletion removes grouping, not people or seats.

Supported limits: 100 combined tables/rows per event, 100 seats per individually edited table/row, 30 sections, 100 managed groups. Bulk table addition supports 20 at a time; numbered-row creation supports up to 30 rows with 30 seats each. The existing event-size limit still applies.

Implementation uses the existing seating service permissions and app-owned aggregate metadata. No core files, registry or schema changes are needed.


## Plan viewport (1.2.1)

The map uses the full workspace width with a taller viewport. It fits the visible tables and rows, including rotated layouts, with padding around the edges. Section selection fits that section. The guest-group panels appear below the plan.

Use **Fit plan** to restore the complete overview, the Zoom slider for detail, and scrolling to explore at higher zoom. **Expand** opens a larger view; use **Close expanded view** or Escape to return. Existing tables, row coordinates and assignments are unchanged.
